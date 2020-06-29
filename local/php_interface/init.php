<?php
class TooManyRequests extends Exception
{
}

class Dadata
{
    private static $base_url = "https://suggestions.dadata.ru/suggestions/api/4_1/rs";
    private static $token;
    private static $handle;

    public function __construct($token)
    {
        self::$token = $token;
    }

    public static function init()
    {
        self::$handle = curl_init();
        curl_setopt(self::$handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt(self::$handle, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Token " . self::$token
        ));
        curl_setopt(self::$handle, CURLOPT_POST, 1);
    }

    public static function geolocate($lat, $lon, $count = 10, $radius_meters = 100)
    {
        $url = self::$base_url . "/geolocate/address";
        $fields = array(
            "lat" => $lat,
            "lon" => $lon,
            "count" => $count,
            "radius_meters" => $radius_meters
        );
        return self::executeRequest($url, $fields);
    }

    /**
     * See https://dadata.ru/api/suggest/ for details.
     */
    public static function suggest($type, $fields)
    {
        $url = self::$base_url . "/suggest/$type";
        return self::executeRequest($url, $fields);
    }

    public static function close()
    {
        curl_close(self::$handle);
    }

    private static function executeRequest($url, $fields)
    {
        curl_setopt(self::$handle, CURLOPT_URL, $url);
        if ($fields != null) {
            curl_setopt(self::$handle, CURLOPT_POST, 1);
            curl_setopt(self::$handle, CURLOPT_POSTFIELDS, json_encode($fields));
        } else {
            curl_setopt(self::$handle, CURLOPT_POST, 0);
        }
        $result = self::exec();
        $result = json_decode($result, true);

        return $result;
    }

    private static function exec()
    {
        $result = curl_exec(self::$handle);
        $info = curl_getinfo(self::$handle);
        if ($info['http_code'] == 429) {
            throw new TooManyRequests();
        } elseif ($info['http_code'] != 200) {
            throw new Exception('Request failed with http code ' . $info['http_code'] . ': ' . $result);
        }
        return $result;
    }
}
class AddHLBlockWithStreets extends Dadata
{
    /**
     * @var float $geo_lat
     * @var float $geo_lon
     * @var int $count
     * @var int $radius
     * @var array $hlTable
     * @var array $hlTableLang
     */
    private static $geo_lat;
    private static $geo_lon;
    private static $count;
    private static $radius;
    private static $hlTable = [
        'NAME' => 'Streets',
        'TABLE_NAME' => 'a_hl_str_msc',
    ];
    private static $hlTableLang = [
        'ru' => 'Справочник: Улицы Москвы',
        'en' => 'Dictionary: Streets of Moscow'
    ];

    public function __construct($token, float $geo_lat, float $geo_lon, int $count = 10, int $radius = 100)
    {
        parent::__construct($token);
        self::$geo_lat = $geo_lat;
        self::$geo_lon = $geo_lon;
        self::$count = $count;
        self::$radius = $radius;
    }

    /**
     * @param float $geo_lat
     * @param float $geo_lon
     * @param int $count
     * @param int $radius
     * @return array
     */
    public static function getStreets(float $geo_lat, float $geo_lon, int $count = 10, int $radius = 100)
    {
        Dadata::init();

        $hlRows = [];

        $result = self::geolocate($geo_lat, $geo_lon, $count, $radius);

        foreach ($result['suggestions'] as $key => $value) {
            // 60.0001087
            $geoLat = $value['data']['geo_lat'];
            // 30.2562129
            $geoLon = $value['data']['geo_lon'];
            // ул Гаккелевская or Комсомольский пр-кт or Шелепихинская наб and etc.
            $streetName = $value['unrestricted_value'];

            if (!empty($streetName)) {
                $hlRows[$key]['geoLatPosition'] = $geoLat;
                $hlRows[$key]['geoLonPosition'] = $geoLon;
                $hlRows[$key]['address'] = $streetName;
            }
        }
        Dadata::close();
        return $hlRows;
    }

    public static function installDependencies(): \Bitrix\Main\Result
    {
        $result = new \Bitrix\Main\Result();

        // инициализация hl блока
        $initializeHlResult = self::initializeHl();
        if (!$initializeHlResult->isSuccess()) {
            $result->addErrors($initializeHlResult->getErrors());
        }

        return $result;
    }

    public static function initializeHl(): \Bitrix\Main\Result
    {
        $result = new \Bitrix\Main\Result();

        if (!\Bitrix\Main\Loader::IncludeModule('highloadblock')) {
            $result->addError(new \Bitrix\Main\Error('Модуль highloadblock найден'));
            return $result;
        }

        $hlTable = self::getKnowledgeHlTable();

        if (empty($hlTable)) {
            // создание hl блока
            $addHlResult = self::addHlKnowledge();
            if (!$addHlResult->isSuccess()) {
                $result->addErrors($addHlResult->getErrors());
            }

            // добавлние пользовательских свойств
            $addUserFieldsResult = self::addUserFields();
            if (!$addUserFieldsResult->isSuccess()) {
                $result->addErrors($addUserFieldsResult->getErrors());
            }

            // заполнение справочника
            $fillUserFieldsResult = self::fillUserFields();
            if (!$fillUserFieldsResult->isSuccess()) {
                $result->addErrors($fillUserFieldsResult->getErrors());
            }
        } else {
            // заполнение справочника
            $fillUserFieldsResult = self::fillUserFields();
            if (!$fillUserFieldsResult->isSuccess()) {
                $result->addErrors($fillUserFieldsResult->getErrors());
            }
        }



        return $result;
    }

    /**
     * Добавление hl блока в систмеу
     * @return \Bitrix\Main\Result
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function addHlKnowledge(): \Bitrix\Main\Result
    {
        $result = new \Bitrix\Main\Result();

        $hlTable = self::getKnowledgeHlTable();

        if (empty($hlTable)) {
            $res = \Bitrix\Highloadblock\HighloadBlockTable::add(self::$hlTable);
            if ($res->isSuccess() && $res->getId() > 0) {
                foreach (self::$hlTableLang ?? [] as $langLid => $langName) {
                    \Bitrix\Highloadblock\HighloadBlockLangTable::add([
                        'ID' => $res->getId(),
                        'LID' => $langLid,
                        'NAME' => $langName
                    ]);
                }
            } else {
                $result->addErrors($res->getErrors());
            }
        }

        return $result;
    }

    /**
     * Добавление пользовательских свойств
     * @return \Bitrix\Main\Result
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function addUserFields(): \Bitrix\Main\Result
    {
        $result = new \Bitrix\Main\Result();

        $hlTable = self::getKnowledgeHlTable();

        if (!empty($hlTable)) {
            $userFields = [
                'UF_NAME' => [
                    'ENTITY_ID' => "HLBLOCK_{$hlTable['ID']}",
                    'FIELD_NAME' => 'UF_NAME',
                    'USER_TYPE_ID' => 'string',
                    'MULTIPLE' => 'N',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => [
                        'ru' => 'Название',
                        'en' => 'Name'
                    ],
                    "LIST_COLUMN_LABEL" => [
                        'ru' => 'Название',
                        'en' => 'Name'
                    ],
                    "LIST_FILTER_LABEL" => [
                        'ru' => 'Название',
                        'en' => 'Name'
                    ],
                ],
                'UF_GEO_LAT' => [
                    'ENTITY_ID' => "HLBLOCK_{$hlTable['ID']}",
                    'FIELD_NAME' => 'UF_GEO_LAT',
                    'USER_TYPE_ID' => 'string',
                    'MULTIPLE' => 'N',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => [
                        'ru' => 'Гео Lat',
                        'en' => 'Geo Lat'
                    ],
                    "LIST_COLUMN_LABEL" => [
                        'ru' => 'Гео Lat',
                        'en' => 'Geo Lat'
                    ],
                    "LIST_FILTER_LABEL" => [
                        'ru' => 'Гео Lat',
                        'en' => 'Geo Lat'
                    ],
                ],
                'UF_GEO_LON' => [
                    'ENTITY_ID' => "HLBLOCK_{$hlTable['ID']}",
                    'FIELD_NAME' => 'UF_GEO_LON',
                    'USER_TYPE_ID' => 'string',
                    'MULTIPLE' => 'N',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => [
                        'ru' => 'Гео Lon',
                        'en' => 'Geo Lon'
                    ],
                    "LIST_COLUMN_LABEL" => [
                        'ru' => 'Гео Lon',
                        'en' => 'Geo Lon'
                    ],
                    "LIST_FILTER_LABEL" => [
                        'ru' => 'Гео Lon',
                        'en' => 'Geo Lon'
                    ],
                ],
                'UF_XML_ID' => [
                    'ENTITY_ID' => "HLBLOCK_{$hlTable['ID']}",
                    'FIELD_NAME' => 'UF_XML_ID',
                    'USER_TYPE_ID' => 'string',
                    'MULTIPLE' => 'N',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => [
                        'ru' => 'Xml ID',
                        'en' => 'Xml ID'
                    ],
                    "LIST_COLUMN_LABEL" => [
                        'ru' => 'Xml ID',
                        'en' => 'Xml ID'
                    ],
                    "LIST_FILTER_LABEL" => [
                        'ru' => 'Xml ID',
                        'en' => 'Xml ID'
                    ],
                ],
            ];

            $userTypeEntity = new CUserTypeEntity();
            foreach ($userFields as $userField) {
                $_field = CUserTypeEntity::GetList([], [
                    'ENTITY_ID' => $userField['ENTITY_ID'],
                    'FIELD_NAME' => $userField['FIELD_NAME'],
                ])->Fetch();

                if (empty($_field)) {
                    $res = $userTypeEntity->Add($userField);
                    if (!$res) {
                        $result->addError(new \Bitrix\Main\Error("При добавлении поля {$userField['FIELD_NAME']} возникла ошибка."));
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Заполнение справочника
     * @return \Bitrix\Main\Result
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function fillUserFields(): \Bitrix\Main\Result
    {
        $result = new \Bitrix\Main\Result();
        $hlRows = self::getStreets(self::$geo_lat, self::$geo_lon, self::$count, self::$radius);
        $hlTable = self::getKnowledgeHlTable();


        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlTable);
        $knowledge = $entity->getDataClass();

        global $DB;
        $results = $DB->Query("DELETE FROM `a_hl_str_msc`");
        while ($row = $results->Fetch()) {
        }

        // заполнение справочника
        foreach ($hlRows as $hlRow) {
            $xmlId = CUtil::translit($hlRow['address'], 'ru');

            $res = $knowledge::add([
                "UF_NAME" => $hlRow['address'],
                "UF_GEO_LAT" => $hlRow['geoLatPosition'],
                "UF_GEO_LON" => $hlRow['geoLonPosition'],
                "UF_XML_ID" => $xmlId,
            ]);
            if (!$res->isSuccess()) {
                $result->addErrors($res->getErrors());
            }
        }

        return $result;
    }

    /**
     * Получение данных о hl
     * @return array|false
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function getKnowledgeHlTable()
    {
        $hlTable = \Bitrix\Highloadblock\HighloadBlockTable::getList([
            'filter' => [
                '=NAME' => self::$hlTable['NAME'],
                '=TABLE_NAME' => self::$hlTable['TABLE_NAME'],
            ]
        ]);

        return $hlTable->fetch();
    }
}
