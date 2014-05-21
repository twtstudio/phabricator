<?php

final class PhabricatorCommitSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter(
      'auditorPHIDs',
      $this->readPHIDsFromRequest($request, 'auditorPHIDs'));

    $saved->setParameter(
      'commitAuthorPHIDs',
      $this->readUsersFromRequest($request, 'commitAuthorPHIDs'));

    $saved->setParameter(
      'auditStatus',
      $request->getStr('auditStatus'));

    $saved->setParameter(
      'repositoryPHIDs',
      $this->readPHIDsFromRequest($request, 'repositoryPHIDs'));

    // -- TODO - T4173 - file location

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new DiffusionCommitQuery())
      ->needAuditRequests(true)
      ->needCommitData(true);

    $auditor_phids = $saved->getParameter('auditorPHIDs', array());
    if ($auditor_phids) {
      $query->withAuditorPHIDs($auditor_phids);
    }

    $commit_author_phids = $saved->getParameter('commitAuthorPHIDs', array());
    if ($commit_author_phids) {
      $query->withAuthorPHIDs($commit_author_phids);
    }

    $audit_status = $saved->getParameter('auditStatus', null);
    if ($audit_status) {
      $query->withAuditStatus($audit_status);
    }

    $awaiting_user_phid = $saved->getParameter('awaitingUserPHID', null);
    if ($awaiting_user_phid) {
      // This is used only for the built-in "needs attention" filter,
      // so cheat and just use the already-loaded viewer rather than reloading
      // it.
      $query->withAuditAwaitingUser($this->requireViewer());
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved) {

    $auditor_phids = $saved->getParameter('auditorPHIDs', array());
    $commit_author_phids = $saved->getParameter(
      'commitAuthorPHIDs',
      array());
    $audit_status = $saved->getParameter('auditStatus', null);

    $phids = array_mergev(
      array(
        $auditor_phids,
        $commit_author_phids));

    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($this->requireViewer())
      ->withPHIDs($phids)
      ->execute();

    $form
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/usersprojectsorpackages/')
          ->setName('auditorPHIDs')
          ->setLabel(pht('Auditors'))
          ->setValue(array_select_keys($handles, $auditor_phids)))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/users/')
          ->setName('commitAuthorPHIDs')
          ->setLabel(pht('Commit Authors'))
          ->setValue(array_select_keys($handles, $commit_author_phids)))
       ->appendChild(
         id(new AphrontFormSelectControl())
         ->setName('auditStatus')
         ->setLabel(pht('Audit Status'))
         ->setOptions($this->getAuditStatusOptions())
         ->setValue($audit_status));
  }

  protected function getURI($path) {
    return '/audit/'.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array();

    if ($this->requireViewer()->isLoggedIn()) {
      $names['need_attention'] = pht('Need Attention');
    }
    $names['open'] = pht('Open Audits');

    $names['all'] = pht('All Commits');

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {
    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);
    $viewer = $this->requireViewer();

    switch ($query_key) {
      case 'all':
        return $query;
      case 'open':
        $query->setParameter(
          'auditStatus',
          DiffusionCommitQuery::AUDIT_STATUS_OPEN);
        return $query;
      case 'need_attention':
        $query->setParameter('awaitingUserPHID', $viewer->getPHID());
        $query->setParameter(
          'auditStatus',
          DiffusionCommitQuery::AUDIT_STATUS_OPEN);
        $query->setParameter(
          'auditorPHIDs',
          PhabricatorAuditCommentEditor::loadAuditPHIDsForUser($viewer));
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  private function getAuditStatusOptions() {
    return array(
      DiffusionCommitQuery::AUDIT_STATUS_ANY => pht('Any'),
      DiffusionCommitQuery::AUDIT_STATUS_OPEN => pht('Open'),
      DiffusionCommitQuery::AUDIT_STATUS_CONCERN => pht('Concern Raised'),
    );
  }

}
