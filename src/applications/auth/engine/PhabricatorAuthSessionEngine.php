<?php

/**
 * @task hisec High Security Mode
 */
final class PhabricatorAuthSessionEngine extends Phobject {

  /**
   * Session issued to normal users after they login through a standard channel.
   * Associates the client with a standard user identity.
   */
  const KIND_USER      = 'U';


  /**
   * Session issued to users who login with some sort of credentials but do not
   * have full accounts. These are sometimes called "grey users".
   *
   * TODO: We do not currently issue these sessions, see T4310.
   */
  const KIND_EXTERNAL  = 'X';


  /**
   * Session issued to logged-out users which has no real identity information.
   * Its purpose is to protect logged-out users from CSRF.
   */
  const KIND_ANONYMOUS = 'A';


  /**
   * Session kind isn't known.
   */
  const KIND_UNKNOWN   = '?';


  /**
   * Get the session kind (e.g., anonymous, user, external account) from a
   * session token. Returns a `KIND_` constant.
   *
   * @param   string  Session token.
   * @return  const   Session kind constant.
   */
  public static function getSessionKindFromToken($session_token) {
    if (strpos($session_token, '/') === false) {
      // Old-style session, these are all user sessions.
      return self::KIND_USER;
    }

    list($kind, $key) = explode('/', $session_token, 2);

    switch ($kind) {
      case self::KIND_ANONYMOUS:
      case self::KIND_USER:
      case self::KIND_EXTERNAL:
        return $kind;
      default:
        return self::KIND_UNKNOWN;
    }
  }


  public function loadUserForSession($session_type, $session_token) {
    $session_kind = self::getSessionKindFromToken($session_token);
    switch ($session_kind) {
      case self::KIND_ANONYMOUS:
        // Don't bother trying to load a user for an anonymous session, since
        // neither the session nor the user exist.
        return null;
      case self::KIND_UNKNOWN:
        // If we don't know what kind of session this is, don't go looking for
        // it.
        return null;
      case self::KIND_USER:
        break;
      case self::KIND_EXTERNAL:
        // TODO: Implement these (T4310).
        return null;
    }

    $session_table = new PhabricatorAuthSession();
    $user_table = new PhabricatorUser();
    $conn_r = $session_table->establishConnection('r');
    $session_key = PhabricatorHash::digest($session_token);

    // NOTE: We're being clever here because this happens on every page load,
    // and by joining we can save a query. This might be getting too clever
    // for its own good, though...

    $info = queryfx_one(
      $conn_r,
      'SELECT
          s.id AS s_id,
          s.sessionExpires AS s_sessionExpires,
          s.sessionStart AS s_sessionStart,
          s.highSecurityUntil AS s_highSecurityUntil,
          u.*
        FROM %T u JOIN %T s ON u.phid = s.userPHID
        AND s.type = %s AND s.sessionKey = %s',
      $user_table->getTableName(),
      $session_table->getTableName(),
      $session_type,
      $session_key);

    if (!$info) {
      return null;
    }

    $session_dict = array(
      'userPHID' => $info['phid'],
      'sessionKey' => $session_key,
      'type' => $session_type,
    );
    foreach ($info as $key => $value) {
      if (strncmp($key, 's_', 2) === 0) {
        unset($info[$key]);
        $session_dict[substr($key, 2)] = $value;
      }
    }
    $session = id(new PhabricatorAuthSession())->loadFromArray($session_dict);

    $ttl = PhabricatorAuthSession::getSessionTypeTTL($session_type);

    // If more than 20% of the time on this session has been used, refresh the
    // TTL back up to the full duration. The idea here is that sessions are
    // good forever if used regularly, but get GC'd when they fall out of use.

    if (time() + (0.80 * $ttl) > $session->getSessionExpires()) {
      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
        $conn_w = $session_table->establishConnection('w');
        queryfx(
          $conn_w,
          'UPDATE %T SET sessionExpires = UNIX_TIMESTAMP() + %d WHERE id = %d',
          $session->getTableName(),
          $ttl,
          $session->getID());
      unset($unguarded);
    }

    $user = $user_table->loadFromArray($info);
    $user->attachSession($session);
    return $user;
  }


  /**
   * Issue a new session key for a given identity. Phabricator supports
   * different types of sessions (like "web" and "conduit") and each session
   * type may have multiple concurrent sessions (this allows a user to be
   * logged in on multiple browsers at the same time, for instance).
   *
   * Note that this method is transport-agnostic and does not set cookies or
   * issue other types of tokens, it ONLY generates a new session key.
   *
   * You can configure the maximum number of concurrent sessions for various
   * session types in the Phabricator configuration.
   *
   * @param   const     Session type constant (see
   *                    @{class:PhabricatorAuthSession}).
   * @param   phid|null Identity to establish a session for, usually a user
   *                    PHID. With `null`, generates an anonymous session.
   * @return  string    Newly generated session key.
   */
  public function establishSession($session_type, $identity_phid) {
    // Consume entropy to generate a new session key, forestalling the eventual
    // heat death of the universe.
    $session_key = Filesystem::readRandomCharacters(40);

    if ($identity_phid === null) {
      return self::KIND_ANONYMOUS.'/'.$session_key;
    }

    $session_table = new PhabricatorAuthSession();
    $conn_w = $session_table->establishConnection('w');

    // This has a side effect of validating the session type.
    $session_ttl = PhabricatorAuthSession::getSessionTypeTTL($session_type);

    // Logging-in users don't have CSRF stuff yet, so we have to unguard this
    // write.
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
      id(new PhabricatorAuthSession())
        ->setUserPHID($identity_phid)
        ->setType($session_type)
        ->setSessionKey(PhabricatorHash::digest($session_key))
        ->setSessionStart(time())
        ->setSessionExpires(time() + $session_ttl)
        ->save();

      $log = PhabricatorUserLog::initializeNewLog(
        null,
        $identity_phid,
        PhabricatorUserLog::ACTION_LOGIN);
      $log->setDetails(
        array(
          'session_type' => $session_type,
        ));
      $log->setSession($session_key);
      $log->save();
    unset($unguarded);

    return $session_key;
  }


  /**
   * Require high security, or prompt the user to enter high security.
   *
   * If the user's session is in high security, this method will return a
   * token. Otherwise, it will throw an exception which will eventually
   * be converted into a multi-factor authentication workflow.
   *
   * @param PhabricatorUser User whose session needs to be in high security.
   * @param AphrontReqeust  Current request.
   * @param string          URI to return the user to if they cancel.
   * @return PhabricatorAuthHighSecurityToken Security token.
   */
  public function requireHighSecuritySession(
    PhabricatorUser $viewer,
    AphrontRequest $request,
    $cancel_uri) {

    if (!$viewer->hasSession()) {
      throw new Exception(
        pht('Requiring a high-security session from a user with no session!'));
    }

    $session = $viewer->getSession();

    $token = $this->issueHighSecurityToken($session);
    if ($token) {
      return $token;
    }

    if ($request->isHTTPPost()) {
      $request->validateCSRF();
      if ($request->getExists(AphrontRequest::TYPE_HISEC)) {

        // TODO: Actually verify that the user provided some multi-factor
        // auth credentials here. For now, we just let you enter high
        // security.

        $until = time() + phutil_units('15 minutes in seconds');
        $session->setHighSecurityUntil($until);

        queryfx(
          $session->establishConnection('w'),
          'UPDATE %T SET highSecurityUntil = %d WHERE id = %d',
          $session->getTableName(),
          $until,
          $session->getID());

        $log = PhabricatorUserLog::initializeNewLog(
          $viewer,
          $viewer->getPHID(),
          PhabricatorUserLog::ACTION_ENTER_HISEC);
        $log->save();
      }
    }

    $token = $this->issueHighSecurityToken($session);
    if ($token) {
      return $token;
    }

    throw id(new PhabricatorAuthHighSecurityRequiredException())
      ->setCancelURI($cancel_uri);
  }


  /**
   * Issue a high security token for a session, if authorized.
   *
   * @param PhabricatorAuthSession Session to issue a token for.
   * @return PhabricatorAuthHighSecurityToken|null Token, if authorized.
   */
  private function issueHighSecurityToken(PhabricatorAuthSession $session) {
    $until = $session->getHighSecurityUntil();
    if ($until > time()) {
      return new PhabricatorAuthHighSecurityToken();
    }
    return null;
  }


  /**
   * Render a form for providing relevant multi-factor credentials.
   *
   * @param   PhabricatorUser Viewing user.
   * @param   AphrontRequest  Current request.
   * @return  AphrontFormView Renderable form.
   */
  public function renderHighSecurityForm(
    PhabricatorUser $viewer,
    AphrontRequest $request) {

    // TODO: This is stubbed.

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendRemarkupInstructions('')
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Secret Stuff')))
      ->appendRemarkupInstructions('');

    return $form;
  }


  public function exitHighSecurity(
    PhabricatorUser $viewer,
    PhabricatorAuthSession $session) {

    queryfx(
      $session->establishConnection('w'),
      'UPDATE %T SET highSecurityUntil = NULL WHERE id = %d',
      $session->getTableName(),
      $session->getID());

    $log = PhabricatorUserLog::initializeNewLog(
      $viewer,
      $viewer->getPHID(),
      PhabricatorUserLog::ACTION_EXIT_HISEC);
    $log->save();
  }

}
