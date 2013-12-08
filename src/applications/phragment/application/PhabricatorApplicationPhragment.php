<?php

final class PhabricatorApplicationPhragment extends PhabricatorApplication {

  public function getBaseURI() {
    return '/phragment/';
  }

  public function getShortDescription() {
    return pht('Versioned Artifact Storage');
  }

  public function getIconName() {
    return 'phragment';
  }

  public function getTitleGlyph() {
    return "\xE2\x26\xB6";
  }

  public function getApplicationGroup() {
    return self::GROUP_UTILITIES;
  }

  public function isBeta() {
    return true;
  }

  public function canUninstall() {
    return true;
  }

  public function getRoutes() {
    return array(
      '/phragment/' => array(
        '' => 'PhragmentBrowseController',
        'browse/(?P<dblob>.*)' => 'PhragmentBrowseController',
        'create/(?P<dblob>.*)' => 'PhragmentCreateController',
        'update/(?P<dblob>.*)' => 'PhragmentUpdateController',
        'history/(?P<dblob>.*)' => 'PhragmentHistoryController',
        'zip/(?P<dblob>.*)' => 'PhragmentZIPController',
        'version/(?P<id>[0-9]*)/' => 'PhragmentVersionController',
        'patch/(?P<aid>[0-9x]*)/(?P<bid>[0-9]*)/' => 'PhragmentPatchController',
      ),
    );
  }

}
