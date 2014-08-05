<?php

final class ReleephProductQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $active;
  private $ids;
  private $phids;
  private $repositoryPHIDs;

  private $needArcanistProjects;

  private $order    = 'order-id';
  const ORDER_ID    = 'order-id';
  const ORDER_NAME  = 'order-name';

  public function withActive($active) {
    $this->active = $active;
    return $this;
  }

  public function setOrder($order) {
    $this->order = $order;
    return $this;
  }

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withRepositoryPHIDs(array $repository_phids) {
    $this->repositoryPHIDs = $repository_phids;
    return $this;
  }

  public function needArcanistProjects($need) {
    $this->needArcanistProjects = $need;
    return $this;
  }

  public function loadPage() {
    $table = new ReleephProject();
    $conn_r = $table->establishConnection('r');

    $rows = queryfx_all(
      $conn_r,
      'SELECT * FROM %T %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $table->loadAllFromArray($rows);
  }

  public function willFilterPage(array $projects) {
    assert_instances_of($projects, 'ReleephProject');

    $repository_phids = mpull($projects, 'getRepositoryPHID');

    $repositories = id(new PhabricatorRepositoryQuery())
      ->setViewer($this->getViewer())
      ->withPHIDs($repository_phids)
      ->execute();
    $repositories = mpull($repositories, null, 'getPHID');

    foreach ($projects as $key => $project) {
      $repo = idx($repositories, $project->getRepositoryPHID());
      if (!$repo) {
        unset($projects[$key]);
        continue;
      }
      $project->attachRepository($repo);
    }

    return $projects;
  }

  public function didFilterPage(array $products) {
    if ($this->needArcanistProjects) {
      $project_ids = array_filter(mpull($products, 'getArcanistProjectID'));
      if ($project_ids) {
        $projects = id(new PhabricatorRepositoryArcanistProject())
          ->loadAllWhere('id IN (%Ld)', $project_ids);
        $projects = mpull($projects, null, 'getID');
      } else {
        $projects = array();
      }

      foreach ($products as $product) {
        $project_id = $product->getArcanistProjectID();
        $project = idx($projects, $project_id);
        $product->attachArcanistProject($project);
      }
    }

    return $products;
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->active !== null) {
      $where[] = qsprintf(
        $conn_r,
        'isActive = %d',
        (int)$this->active);
    }

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ls)',
        $this->ids);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->repositoryPHIDs !== null) {
      $where[] = qsprintf(
        $conn_r,
        'repositoryPHID IN (%Ls)',
        $this->repositoryPHIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  protected function getReversePaging() {
    switch ($this->order) {
      case self::ORDER_NAME:
        return true;
    }
    return parent::getReversePaging();
  }

  protected function getPagingValue($result) {
    switch ($this->order) {
      case self::ORDER_NAME:
        return $result->getName();
    }
    return parent::getPagingValue();
  }

  protected function getPagingColumn() {
    switch ($this->order) {
      case self::ORDER_NAME:
        return 'name';
      case self::ORDER_ID:
        return parent::getPagingColumn();
      default:
        throw new Exception("Uknown order '{$this->order}'!");
    }
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorReleephApplication';
  }

}
