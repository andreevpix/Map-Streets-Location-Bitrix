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
     * @var string
     */
    private static $query;
    /**
     * @var int
     */
    private static $count;
    /**
     * @var array
     */
    private static $region;

    public function __construct($token, string $query, int $count, array $region)
    {
        parent::__construct($token);
        self::$query = $query;
        self::$count = $count;
        self::$region = $region;
    }

    private static $hlTable = [
        'NAME' => 'Streets',
        'TABLE_NAME' => 'a_hl_str_msc',
    ];

    private static $hlTableLang = [
        'ru' => 'Справочник: Улицы Москвы',
        'en' => 'Dictionary: Streets of Moscow'
    ];

    public static function getStreets(string $query, int $count, array $region)
    {
        Dadata::init();

        $hlRows = [];
        $fields = array("query" => $query, "count" => $count, "locations" => [$region]);
        $result = Dadata::suggest("party", $fields);

        foreach ($result['suggestions'] as $key => $value) {
            $hlRows[$key]['geoLatPosition'] = $value['data']['address']['data']['geo_lat'];
            $hlRows[$key]['geoLonPosition'] = $value['data']['address']['data']['geo_lon'];
            $hlRows[$key]['address'] = $value['data']['address']['data']['street_with_type']
                . ', '
                . $value['data']['address']['data']['house_type_full']
                . ' '
                . $value['data']['address']['data']['house'];
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
                'UF_GEO' => [
                    'ENTITY_ID' => "HLBLOCK_{$hlTable['ID']}",
                    'FIELD_NAME' => 'UF_GEO',
                    'USER_TYPE_ID' => 'string',
                    'MULTIPLE' => 'N',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => [
                        'ru' => 'Геолокация',
                        'en' => 'Geolocation'
                    ],
                    "LIST_COLUMN_LABEL" => [
                        'ru' => 'Геолокация',
                        'en' => 'Geolocation'
                    ],
                    "LIST_FILTER_LABEL" => [
                        'ru' => 'Геолокация',
                        'en' => 'Geolocation'
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
        $hlRows = self::getStreets(self::$query, self::$count, self::$region);
        $hlTable = self::getKnowledgeHlTable();

        if (!empty($hlTable)) {
            $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlTable);
            $knowledge = $entity->getDataClass();

            // поиск имеющихся записей
            $rsItems = $knowledge::getList();
            $exItems = [];
            while ($item = $rsItems->fetch()) {
                $exItems[$item['UF_XML_ID']] = $item;
            }

            // заполнение справочника
            foreach ($hlRows as $hlRow) {
                $xmlId = CUtil::translit($hlRow, 'ru');

                if (empty($exItems[$xmlId])) {
                    $res = $knowledge::add([
                        "UF_NAME" => $hlRow['address'],
                        "UF_GEO" => $hlRow['geoLatPosition'] . ', ' . $hlRow['geoLonPosition'],
                        "UF_XML_ID" => $xmlId,
                    ]);
                    if (!$res->isSuccess()) {
                        $result->addErrors($res->getErrors());
                    }
                }
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