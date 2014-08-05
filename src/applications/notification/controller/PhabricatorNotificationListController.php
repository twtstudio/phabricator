<?php

final class PhabricatorNotificationListController
  extends PhabricatorNotificationController {

  private $filter;

  public function willProcessRequest(array $data) {
    $this->filter = idx($data, 'filter');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI('/notification/'));
    $nav->addFilter('all', pht('All Notifications'));
    $nav->addFilter('unread', pht('Unread Notifications'));
    $filter = $nav->selectFilter($this->filter, 'all');

    $pager = new AphrontPagerView();
    $pager->setURI($request->getRequestURI(), 'offset');
    $pager->setOffset($request->getInt('offset'));

    $query = new PhabricatorNotificationQuery();
    $query->setViewer($user);
    $query->setUserPHID($user->getPHID());

    switch ($filter) {
      case 'unread':
        $query->withUnread(true);
        $header = pht('Unread Notifications');
        $no_data = pht('You have no unread notifications.');
        break;
      default:
        $header = pht('Notifications');
        $no_data = pht('You have no notifications.');
        break;
    }

    $image = id(new PHUIIconView())
      ->setIconFont('fa-eye-slash');
    $button = id(new PHUIButtonView())
      ->setTag('a')
      ->addSigil('workflow')
      ->setColor(PHUIButtonView::SIMPLE)
      ->setIcon($image)
      ->setText(pht('Mark All Read'));

    $notifications = $query->executeWithOffsetPager($pager);
    $clear_uri = id(new PhutilURI('/notification/clear/'));
    if ($notifications) {
      $builder = new PhabricatorNotificationBuilder($notifications);
      $builder->setUser($user);
      $view = $builder->buildView()->render();
      $clear_uri->setQueryParam(
        'chronoKey',
        head($notifications)->getChronologicalKey());
    } else {
      $view = phutil_tag_div(
        'phabricator-notification no-notifications',
        $no_data);
      $button->setDisabled(true);
    }
    $button->setHref((string) $clear_uri);

    $view = id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_MEDIUM)
      ->addClass('phabricator-notification-list')
      ->appendChild($view);

    $notif_header = id(new PHUIHeaderView())
      ->setHeader($header)
      ->addActionLink($button);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($notif_header)
      ->appendChild($view);

    $nav->appendChild($box);
    $nav->appendChild($pager);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Notifications'),
      ));
  }

}
