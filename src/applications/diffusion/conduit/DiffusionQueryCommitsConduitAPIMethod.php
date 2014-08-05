<?php

final class DiffusionQueryCommitsConduitAPIMethod
  extends DiffusionConduitAPIMethod {

  public function getAPIMethodName() {
    return 'diffusion.querycommits';
  }

  public function getMethodDescription() {
    return pht('Retrieve information about commits.');
  }

  public function defineReturnType() {
    return 'map<string, dict>';
  }

  public function defineParamTypes() {
    return array(
      'ids'               => 'optional list<int>',
      'phids'             => 'optional list<phid>',
      'names'             => 'optional list<string>',
      'repositoryPHID'    => 'optional phid',
      'needMessages'      => 'optional bool',
    ) + $this->getPagerParamTypes();
  }

  public function defineErrorTypes() {
    return array();
  }

  protected function execute(ConduitAPIRequest $request) {
    $need_messages = $request->getValue('needMessages');

    $query = id(new DiffusionCommitQuery())
      ->setViewer($request->getUser());

    if ($need_messages) {
      $query->needCommitData(true);
    }

    $repository_phid = $request->getValue('repositoryPHID');
    if ($repository_phid) {
      $repository = id(new PhabricatorRepositoryQuery())
        ->setViewer($request->getUser())
        ->withPHIDs(array($repository_phid))
        ->executeOne();
      if ($repository) {
        $query->withRepository($repository);
      }
    }

    $names = $request->getValue('names');
    if ($names) {
      $query->withIdentifiers($names);
    }

    $ids = $request->getValue('ids');
    if ($ids) {
      $query->withIDs($ids);
    }

    $phids = $request->getValue('phids');
    if ($phids) {
      $query->withPHIDs($phids);
    }

    $pager = $this->newPager($request);
    $commits = $query->executeWithCursorPager($pager);

    $map = $query->getIdentifierMap();
    $map = mpull($map, 'getPHID');

    $data = array();
    foreach ($commits as $commit) {
      $callsign = $commit->getRepository()->getCallsign();
      $identifier = $commit->getCommitIdentifier();
      $uri = '/r'.$callsign.$identifier;
      $uri = PhabricatorEnv::getProductionURI($uri);

      $dict = array(
        'id' => $commit->getID(),
        'phid' => $commit->getPHID(),
        'repositoryPHID' => $commit->getRepository()->getPHID(),
        'identifier' => $identifier,
        'epoch' => $commit->getEpoch(),
        'uri' => $uri,
        'isImporting' => !$commit->isImported(),
        'summary' => $commit->getSummary(),
      );

      if ($need_messages) {
        $commit_data = $commit->getCommitData();
        if ($commit_data) {
          $dict['message'] = $commit_data->getCommitMessage();
        } else {
          $dict['message'] = null;
        }
      }

      $data[$commit->getPHID()] = $dict;
    }

    $result = array(
      'data' => $data,
      'identifierMap' => nonempty($map, (object)array()),
    );

    return $this->addPagerResults($result, $pager);
  }

}
