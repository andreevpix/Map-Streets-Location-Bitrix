<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");
$res = new AddHLBlockWithStreets('aee0f2da9a55b3f75c37d48481d35010789c3ba1', 'сек', 50, ['region' => 'москва']);
$res::installDependencies();
if (!$res->isSuccess()) {
    echo "<pre>";
    print_r(['errors' => $res->getErrors()]);
    echo "</pre>";
}
