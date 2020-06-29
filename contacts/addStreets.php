<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
$res = new AddHLBlockWithStreets(
    '',
    ($_POST['geoLat']) ? $_POST['geoLat'] : 55.756559,
    ($_POST['geoLon']) ? $_POST['geoLat'] : 37.618129,
    ($_POST['count']) ? $_POST['count'] : 50,
    ($_POST['radius']) ? $_POST['radius'] : 1000
);
$res = AddHLBlockWithStreets::installDependencies();

use Bitrix\Highloadblock\HighloadBlockTable as HL;

if (CModule::IncludeModule('highloadblock')) {
    $hlblock_id = 11; // ID Highload-блока
    $hlblock   = HL::getById($hlblock_id)->fetch(); // объект HL блока
    $entity   = HL::compileEntity($hlblock);  // рабочая сущность
    $entity_data_class = $entity->getDataClass(); // экземпляр класса
    $entity_table_name = $hlblock['TABLE_NAME']; // присваиваивание названия HL таблицы
    $sTableID = 'tbl_'.$entity_table_name; // префикс и формирование названия

    $arSelect = array('*'); // выбираем все поля
    $arOrder = array("ID"=>"ASC"); // сортировка по возрастанию ID статей

    // подготавка данных
    $rsData = $entity_data_class::getList(array(
        "select" => $arSelect,
        "limit" => '100', //ограничение выборки пятью элементами
        "order" => $arOrder
    ));

    $result = new CDBResult($rsData);

    while ($arRes = $result->Fetch()) {
        $myPoints[] = $arRes;
    }
}

echo json_encode($myPoints);

