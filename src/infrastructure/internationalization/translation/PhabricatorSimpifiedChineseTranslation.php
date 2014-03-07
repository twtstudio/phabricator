<?php

final class PhabricatorSimplifiedChineseTranslation
  extends PhabricatorTranslation {

  public function getLanguage() {
    return 'zh-cn';
  }

  public function getName() {
    return pht('Chinese (Simplified)');
  }

  public function getTranslations() {
    return
      PhabricatorEnv::getEnvConfig('translation.override') + require(dirname(__FILE__) . '/zh_CN.php');
  }

}
