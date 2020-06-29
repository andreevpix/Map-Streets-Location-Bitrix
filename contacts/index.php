<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php"); ?>

<style>
#map {
	width: 80vw; height: 100vh; padding: 0; margin: 0;
}
</style>
<script type="text/javascript" src="https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=60445215-6d3a-4f88-87fe-8d52b72e5bc9"></script>

<form action="/" id="searchForm">
    <label for="geo-lat">GEO LAT</label><input type="number" id="geo-lat">
    <label for="geo-lon">GEO LON</label><input type="number" id="geo-lon">
    <label for="count">Количество точек</label><input type="number" id="count" max="20">
    <label for="radius">Радиус (метр)</label><input type="number" id="radius" max="1000">
    <input type="submit">
</form>

<div id="map">

</div>
<script>

    function mapInitialisation(data) {
        function init() {

            var myMap = new ymaps.Map('map', {
                center: [55.7, 37.5],
                zoom: 9,
                controls: ['zoomControl']
            });
            // Создаем коллекцию.
            var myCollection = new ymaps.GeoObjectCollection();
            // Создаем массив с данными.

            var len = data.length;
            var myPoints = [];
            for(var i=0; i<len; i++){
                myPoints.push({coords: [data[i].UF_GEO_LAT, data[i].UF_GEO_LON], text: data[i].UF_NAME});
            }

            console.log(myPoints);

            // Заполняем коллекцию данными.
            for (var i = 0, l = myPoints.length; i < l; i++) {
                var point = myPoints[i];
                myCollection.add(new ymaps.Placemark(
                    point.coords, {
                        balloonContentBody: point.text
                    }
                ));
            }

            // Добавляем коллекцию меток на карту.
            myMap.geoObjects.add(myCollection);

            // Создаем экземпляр класса ymaps.control.SearchControl
            var mySearchControl = new ymaps.control.SearchControl({
                options: {
                    // Заменяем стандартный провайдер данных (геокодер) нашим собственным.
                    provider: new CustomSearchProvider(myPoints),
                    // Не будем показывать еще одну метку при выборе результата поиска,
                    // т.к. метки коллекции myCollection уже добавлены на карту.
                    noPlacemark: true,
                    resultsPerPage: 5
                }});

            // Добавляем контрол в верхний правый угол,
            myMap.controls
                .add(mySearchControl, { float: 'right' });
        }

        // Провайдер данных для элемента управления ymaps.control.SearchControl.
        // Осуществляет поиск геообъектов в по массиву points.
        // Реализует интерфейс IGeocodeProvider.
        function CustomSearchProvider(points) {
            this.points = points;
        }

        // Провайдер ищет по полю text стандартным методом String.ptototype.indexOf.
        CustomSearchProvider.prototype.geocode = function (request, options) {
            var deferred = new ymaps.vow.defer(),
                geoObjects = new ymaps.GeoObjectCollection(),
                // Сколько результатов нужно пропустить.
                offset = options.skip || 0,
                // Количество возвращаемых результатов.
                limit = options.results || 20;

            var points = [];
            // Ищем в свойстве text каждого элемента массива.
            for (var i = 0, l = this.points.length; i < l; i++) {
                var point = this.points[i];
                if (point.text.toLowerCase().indexOf(request.toLowerCase()) != -1) {
                    points.push(point);
                }
            }
            // При формировании ответа можно учитывать offset и limit.
            points = points.splice(offset, limit);
            // Добавляем точки в результирующую коллекцию.
            for (var i = 0, l = points.length; i < l; i++) {
                var point = points[i],
                    coords = point.coords,
                    text = point.text;

                geoObjects.add(new ymaps.Placemark(coords, {
                    name: text + ' name',
                    description: text + ' description',
                    balloonContentBody: '<p>' + text + '</p>',
                    boundedBy: [coords, coords]
                }));
            }

            deferred.resolve({
                // Геообъекты поисковой выдачи.
                geoObjects: geoObjects,
                // Метаинформация ответа.
                metaData: {
                    geocoder: {
                        // Строка обработанного запроса.
                        request: request,
                        // Количество найденных результатов.
                        found: geoObjects.getLength(),
                        // Количество возвращенных результатов.
                        results: limit,
                        // Количество пропущенных результатов.
                        skip: offset
                    }
                }
            });

            // Возвращаем объект-обещание.
            return deferred.promise();
        };

        ymaps.ready(init);
    }
    function mapGetData(geoLat, geoLon, count, radius) {
        $.ajax({
            url: 'addStreets.php',
            type: 'post',
            data: {
                geoLat: (geoLat) ? geoLat : '',
                geoLon: (geoLon) ? geoLon : '',
                count: (count) ? count : '',
                radius: (radius) ? radius : ''
            },
            dataType: 'JSON',
            success: function (data) {
                mapInitialisation(data);
            }

        });
    }
    $(document).ready(function () {
        mapGetData();
    });
    $("#searchForm").submit(function (event) {

        event.preventDefault();

        let geoLat = $('#geo-lat').val();
        let geoLon = $('#geo-lon').val();
        let count = $('#count').val();
        let radius = $('#radius').val();

        $('#map').remove();
        $('.main').append('<div id="map"></div>');

        mapGetData(geoLat, geoLon, count, radius);

    })

</script>
<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>