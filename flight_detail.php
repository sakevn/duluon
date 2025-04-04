<?php
header('Content-Type: text/html; charset=UTF-8');

// Hàm tính khoảng cách Haversine
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}

// Chuyển đổi tọa độ IGC sang độ thập phân
function parseIGCCoordinates($coord) {
    $isLatitude = (strlen($coord) == 8);
    $degrees = (int)substr($coord, 0, $isLatitude ? 2 : 3);
    $minutes = (float)substr($coord, $isLatitude ? 2 : 3, 5) / 1000;
    $decimal = $degrees + ($minutes / 60);
    return ($coord[strlen($coord)-1] == 'S' || $coord[strlen($coord)-1] == 'W') ? -$decimal : $decimal;
}

// Đọc và phân tích file IGC
function parseIGCFile($filePath) {
    $flightData = [
        'pilot' => 'Unknown',
        'date' => 'N/A',
        'glider' => 'Unknown',
        'hardware' => 'Unknown',
        'software' => 'Unknown',
        'takeoff_time' => '',
        'landing_time' => '',
        'distance' => 0,
        'max_altitude' => 0,
        'coordinates' => [],
        'turnpoints' => [],
        'altitudes' => [],
        'times' => [],
        'speeds' => [],
        'varios' => []
    ];

    if (!file_exists($filePath)) {
        die("File IGC không tồn tại!");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $prevLat = $prevLon = $prevAlt = $prevTime = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, 'HFPLTPILOT') === 0) {
            $flightData['pilot'] = substr($line, 10);
        } elseif (strpos($line, 'HFDTE') === 0) {
            $dateStr = substr($line, 5, 6);
            if (strlen($dateStr) == 6 && ctype_digit($dateStr)) {
                $flightData['date'] = substr($dateStr, 0, 2) . '.' . substr($dateStr, 2, 2) . '.20' . substr($dateStr, 4, 2);
            }
        } elseif (strpos($line, 'HFGTYGLIDERTYPE') === 0) {
            $flightData['glider'] = substr($line, 15);
        } elseif (strpos($line, 'HFRHWHARDWAREVERSION') === 0) {
            $flightData['hardware'] = substr($line, 20);
        } elseif (strpos($line, 'HFFTYFRTYPE') === 0) {
            $flightData['software'] = substr($line, 11);
        } elseif (strpos($line, 'C') === 0 && strpos($line, 'TASK') === false) {
            $lat = parseIGCCoordinates(substr($line, 1, 8));
            $lon = parseIGCCoordinates(substr($line, 9, 9));
            $desc = substr($line, 18);
            $flightData['turnpoints'][] = ['lat' => $lat, 'lon' => $lon, 'desc' => $desc];
        } elseif (strpos($line, 'B') === 0) {
            $time = substr($line, 1, 6);
            $lat = substr($line, 7, 8);
            $lon = substr($line, 15, 9);
            $alt = (int)substr($line, 25, 5);

            if (!$flightData['takeoff_time']) {
                $flightData['takeoff_time'] = $time;
            }
            $flightData['landing_time'] = $time;

            $latDecimal = parseIGCCoordinates($lat);
            $lonDecimal = parseIGCCoordinates($lon);
            $flightData['coordinates'][] = ['lat' => $latDecimal, 'lng' => $lonDecimal];
            $flightData['altitudes'][] = $alt;
            $flightData['times'][] = $time;

            if ($prevLat !== null && $prevLon !== null && $prevTime !== null) {
                $distance = calculateDistance($prevLat, $prevLon, $latDecimal, $lonDecimal);
                $timeDiff = (strtotime("1970-01-01 $time UTC") - strtotime("1970-01-01 $prevTime UTC")) / 3600;
                $speed = $timeDiff > 0 ? $distance / $timeDiff : 0;
                $vario = $timeDiff > 0 ? ($alt - $prevAlt) / ($timeDiff * 3600) : 0;
                $flightData['speeds'][] = $speed;
                $flightData['varios'][] = $vario;
            } else {
                $flightData['speeds'][] = 0;
                $flightData['varios'][] = 0;
            }
            $prevLat = $latDecimal;
            $prevLon = $lonDecimal;
            $prevAlt = $alt;
            $prevTime = $time;

            $flightData['max_altitude'] = max($flightData['max_altitude'], $alt);
        }
    }

    $timezoneOffset = 7;
    $takeoff = DateTime::createFromFormat('His', $flightData['takeoff_time']);
    $landing = DateTime::createFromFormat('His', $flightData['landing_time']);
    if ($takeoff && $landing) {
        $takeoff->modify("+$timezoneOffset hours");
        $landing->modify("+$timezoneOffset hours");
        $interval = $takeoff->diff($landing);
        $flightData['duration'] = $interval->format('%h:%i:%s');
        $flightData['takeoff_time'] = $takeoff->format('H:i:s');
        $flightData['landing_time'] = $landing->format('H:i:s');

        $hours = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
        $flightData['avg_speed'] = $hours > 0 ? $flightData['distance'] / $hours : 0;
    } else {
        $flightData['duration'] = 'N/A';
    }

    return $flightData;
}

// Hiển thị thông tin với bản đồ
$filePath = 'flight.igc'; // Thay bằng đường dẫn thực tế
$flightData = parseIGCFile($filePath);
$coordinatesJson = json_encode($flightData['coordinates']);
$altitudesJson = json_encode($flightData['altitudes']);
$timesJson = json_encode($flightData['times']);
$speedsJson = json_encode($flightData['speeds']);
$variosJson = json_encode($flightData['varios']);
$aglJson = json_encode(array_map(function($alt) { return $alt; }, $flightData['altitudes']));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flight Detail - <?php echo htmlspecialchars($flightData['pilot']); ?></title>
    <link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css">
    <link rel="stylesheet" href="https://d393ilck4xazzy.cloudfront.net/widget/flight-map/2.1.1/bundle.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://s.xcontest.org/js/Page.lib.js?1"></script>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; }
        .flight-container { display: flex; max-width: 1200px; margin: 20px auto; }
        .flight-info { flex: 1; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto; max-height: 700px; }
        .map-section { flex: 2; position: relative; }
        #map { width: 100%; height: 500px; opacity: 0; transition: opacity 0.5s; }
        #map.visible { opacity: 1; }
        .fullscreen #map { height: 100vh; }
        .loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 100; }
        .flight-info h1 { margin: 0 0 10px; font-size: 20px; }
        .flight-info h1 em { font-size: 14px; color: #666; }
        .flight-info table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .flight-info th, .flight-info td { padding: 6px; text-align: left; border-bottom: 1px solid #eee; }
        .flight-info th { width: 40%; font-weight: normal; color: #666; }
        #altitude-chart { width: 100%; height: 200px; margin-top: 10px; }
        .controls { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 5px; }
        .controls select, .controls label { padding: 5px; }
        .mapboxgl-popup-content { font-size: 12px; padding: 5px; }
        #play-info { margin: 5px 0; font-size: 14px; }
        #time-display { position: absolute; bottom: 10px; right: 10px; background: rgba(255, 255, 255, 0.8); padding: 5px; border-radius: 3px; z-index: 1; }
        .mapboxgl-ctrl-play { position: absolute; bottom: 5px; left: 10px; display: flex; align-items: center; background: #fff; border: 2px solid rgba(0,0,0,0.2); border-radius: 4px; padding: 5px; }
        .mapboxgl-ctrl-play button { border: none; background: none; padding: 5px; cursor: pointer; font-size: 16px; }
        .mapboxgl-ctrl-play span { padding: 5px; }
        .speed-controls { display: none; }
        .speed-controls.visible { display: flex; align-items: center; }
        .mapboxgl-ctrl-custom { display: flex; flex-direction: column; gap: 2px; }
        .mapboxgl-ctrl-custom button, .mapboxgl-ctrl-custom label { display: block; padding: 5px; text-align: center; cursor: pointer; }
        .mapboxgl-ctrl-custom button { background: #fff; border: 2px solid rgba(0,0,0,0.2); border-radius: 4px; }
        .mapboxgl-ctrl-custom label { font-size: 12px; }
        .mapboxgl-ctrl-play button:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="flight-container">
        <div class="flight-info">
            <h1>Flight Detail <em><?php echo htmlspecialchars($flightData['pilot']); ?> · <?php echo $flightData['date']; ?> · <?php echo number_format($flightData['distance'], 2); ?> km</em></h1>
            <table>
                <tr><th>Phi công</th><td><?php echo htmlspecialchars($flightData['pilot']); ?></td></tr>
                <tr><th>Ngày</th><td><?php echo $flightData['date']; ?></td></tr>
                <tr><th>Thời gian cất cánh</th><td><?php echo $flightData['takeoff_time']; ?> (UTC+7)</td></tr>
                <tr><th>Thời gian hạ cánh</th><td><?php echo $flightData['landing_time']; ?> (UTC+7)</td></tr>
                <tr><th>Thời gian bay</th><td><?php echo $flightData['duration']; ?></td></tr>
                <tr><th>Khoảng cách</th><td><?php echo number_format($flightData['distance'], 2); ?> km</td></tr>
                <tr><th>Tốc độ trung bình</th><td><?php echo number_format($flightData['avg_speed'], 2); ?> km/h</td></tr>
                <tr><th>Độ cao tối đa</th><td><?php echo $flightData['max_altitude']; ?> m</td></tr>
                <tr><th>Loại dù</th><td><?php echo htmlspecialchars($flightData['glider']); ?></td></tr>
                <tr><th>Phần cứng</th><td><?php echo htmlspecialchars($flightData['hardware']); ?></td></tr>
                <tr><th>Phần mềm</th><td><?php echo htmlspecialchars($flightData['software']); ?></td></tr>
                <tr><th>Turnpoints</th><td>
                    <?php
                    foreach ($flightData['turnpoints'] as $i => $tp) {
                        if ($i > 0 && $i < count($flightData['turnpoints']) - 1) {
                            echo number_format($tp['lat'], 5) . ' N, ' . number_format($tp['lon'], 5) . ' E (' . htmlspecialchars($tp['desc']) . ')<br>';
                        }
                    }
                    ?>
                </td></tr>
            </table>
        </div>

        <div class="map-section">
            <div id="map"></div>
            <div class="loading">Đang tải dữ liệu...</div>
            <div id="time-display"></div>
            <div class="mapboxgl-ctrl-play">
                <button id="playPause" disabled>▶</button>
                <div id="speedControls" class="speed-controls">
                    <button id="speedDown">-</button>
                    <span id="speedDisplay">20x</span>
                    <button id="speedUp">+</button>
                </div>
            </div>
            <div class="controls">
                <label><input type="checkbox" id="show-agl" checked> Show AGL</label>
                <label><input type="checkbox" id="show-speed" checked> Show Speed</label>
                <label><input type="checkbox" id="show-vario"> Show Vario</label>
                <select id="chart-type">
                    <option value="altitude">Độ cao</option>
                    <option value="vario">Vario</option>
                    <option value="speed">Tốc độ</option>
                </select>
            </div>
            <div id="play-info"></div>
            <canvas id="altitude-chart"></canvas>
        </div>
    </div>

    <script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
    <script>
        mapboxgl.accessToken = 'pk.eyJ1IjoieW91cnNha2UiLCJhIjoiY2p5ZnAzbnJiMWVuNzNqazRtb3h2ZmlmYyJ9.7Pz9kdVVVomZU7udihXbyg'; // Thay bằng token thực tế

        const flightData = {
            coordinates: <?php echo $coordinatesJson; ?>,
            altitudes: <?php echo $altitudesJson; ?>,
            times: <?php echo $timesJson; ?>,
            speeds: <?php echo $speedsJson; ?>,
            varios: <?php echo $variosJson; ?>,
            agl: <?php echo $aglJson; ?>
        };

        let map, marker, animationFrame, currentIndex = 0;
        let speed = 20; // Tốc độ mặc định (20ms)
        const speedLevels = [1, 2, 5, 10, 20, 50, 100];
        let speedIndex = 4; // Bắt đầu từ 20ms
        let isMapLoaded = false;

        // Custom Mapbox Control cho Fullscreen và các lớp
        class CustomControl {
            onAdd(map) {
                this._map = map;
                this._container = document.createElement('div');
                this._container.className = 'mapboxgl-ctrl mapboxgl-ctrl-custom';

                // Nút Fullscreen
                const fullscreenBtn = document.createElement('button');
                fullscreenBtn.id = 'fullscreen-btn';
                fullscreenBtn.textContent = 'Fullscreen';
                fullscreenBtn.addEventListener('click', () => {
                    const mapSection = document.querySelector('.map-section');
                    if (!document.fullscreenElement) {
                        mapSection.requestFullscreen();
                        mapSection.classList.add('fullscreen');
                    } else {
                        document.exitFullscreen();
                        mapSection.classList.remove('fullscreen');
                    }
                    map.resize();
                });
                this._container.appendChild(fullscreenBtn);

                // Checkbox Hi-res Tracklog
                const hiresLabel = document.createElement('label');
                const hiresCheckbox = document.createElement('input');
                hiresCheckbox.type = 'checkbox';
                hiresCheckbox.id = 'hires-checkbox';
                hiresLabel.appendChild(hiresCheckbox);
                hiresLabel.appendChild(document.createTextNode(' Hi-res Tracklog'));
                hiresCheckbox.addEventListener('change', updateMapDisplay);
                this._container.appendChild(hiresLabel);

                // Checkbox Sat Map
                const satLabel = document.createElement('label');
                const satCheckbox = document.createElement('input');
                satCheckbox.type = 'checkbox';
                satCheckbox.id = 'sat-checkbox';
                satLabel.appendChild(satCheckbox);
                satLabel.appendChild(document.createTextNode(' Sat Map'));
                satCheckbox.addEventListener('change', updateMapDisplay);
                this._container.appendChild(satLabel);

                // Checkbox Airspace
                const airspaceLabel = document.createElement('label');
                const airspaceCheckbox = document.createElement('input');
                airspaceCheckbox.type = 'checkbox';
                airspaceCheckbox.id = 'airspace-checkbox';
                airspaceLabel.appendChild(airspaceCheckbox);
                airspaceLabel.appendChild(document.createTextNode(' Airspace'));
                airspaceCheckbox.addEventListener('change', updateMapDisplay);
                this._container.appendChild(airspaceLabel);

                return this._container;
            }

            onRemove() {
                this._container.parentNode.removeChild(this._container);
                this._map = undefined;
            }
        }

        function initMap() {
            map = new mapboxgl.Map({
                container: 'map',
                style: 'mapbox://styles/mapbox/streets-v11',
                center: [flightData.coordinates[0].lng, flightData.coordinates[0].lat],
                zoom: 12
            });

            map.addControl(new mapboxgl.NavigationControl(), 'top-right');
            map.addControl(new CustomControl(), 'top-right');

            map.on('load', () => {
                const coordinates = flightData.coordinates.map(c => [c.lng, c.lat]);

                map.addSource('flight-path', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        geometry: {
                            type: 'LineString',
                            coordinates: coordinates
                        }
                    }
                });

                map.addLayer({
                    id: 'flight-path',
                    type: 'line',
                    source: 'flight-path',
                    paint: {
                        'line-color': '#FF0000',
                        'line-width': 2
                    }
                });

                map.addSource('flight-points', {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: flightData.coordinates.map((point, i) => ({
                            type: 'Feature',
                            geometry: {
                                type: 'Point',
                                coordinates: [point.lng, point.lat]
                            },
                            properties: {
                                time: flightData.times[i],
                                altitude: flightData.altitudes[i],
                                speed: flightData.speeds[i],
                                vario: flightData.varios[i],
                                agl: flightData.agl[i]
                            }
                        }))
                    }
                });

                map.addLayer({
                    id: 'flight-points',
                    type: 'circle',
                    source: 'flight-points',
                    paint: {
                        'circle-radius': 3,
                        'circle-color': '#00f'
                    }
                });

                const popup = new mapboxgl.Popup({
                    closeButton: false,
                    closeOnClick: false
                });

                map.on('mouseenter', 'flight-points', (e) => {
                    map.getCanvas().style.cursor = 'pointer';
                    const props = e.features[0].properties;
                    const coords = e.features[0].geometry.coordinates;
                    popup
                        .setLngLat(coords)
                        .setHTML(`
                            <strong>Thời gian:</strong> ${props.time}<br>
                            <strong>Độ cao:</strong> ${props.altitude} m<br>
                            <strong>Tốc độ:</strong> ${props.speed.toFixed(2)} km/h<br>
                            <strong>Vario:</strong> ${props.vario.toFixed(2)} m/s<br>
                            <strong>AGL:</strong> ${props.agl} m
                        `)
                        .addTo(map);
                });

                map.on('mouseleave', 'flight-points', () => {
                    map.getCanvas().style.cursor = '';
                    popup.remove();
                });

                new mapboxgl.Marker({ color: '#00FF00' })
                    .setLngLat([flightData.coordinates[0].lng, flightData.coordinates[0].lat])
                    .setPopup(new mapboxgl.Popup().setHTML(`<strong>Cất cánh</strong><br>Thời gian: ${flightData.times[0]}`))
                    .addTo(map);

                new mapboxgl.Marker({ color: '#FF0000' })
                    .setLngLat([flightData.coordinates[flightData.coordinates.length-1].lng, flightData.coordinates[flightData.coordinates.length-1].lat])
                    .setPopup(new mapboxgl.Popup().setHTML(`<strong>Hạ cánh</strong><br>Thời gian: ${flightData.times[flightData.times.length-1]}`))
                    .addTo(map);

                marker = new mapboxgl.Marker()
                    .setLngLat([flightData.coordinates[0].lng, flightData.coordinates[0].lat])
                    .addTo(map);

                const bounds = new mapboxgl.LngLatBounds();
                coordinates.forEach(coord => bounds.extend(coord));
                map.fitBounds(bounds, { padding: 50 });

                document.getElementById('map').classList.add('visible');
                document.querySelector('.loading').style.display = 'none';

                // Kích hoạt nút Play sau khi bản đồ tải xong
                isMapLoaded = true;
                document.getElementById('playPause').disabled = false;
            });
        }

        // Chức năng Play
        function playFlight() {
            if (!isMapLoaded || !marker) {
                console.error('Map or marker not ready yet.');
                return;
            }

            if (currentIndex >= flightData.coordinates.length) {
                currentIndex = 0;
            }

            // Cập nhật vị trí marker trên bản đồ
            marker.setLngLat([flightData.coordinates[currentIndex].lng, flightData.coordinates[currentIndex].lat]);

            // Cập nhật biểu đồ: làm nổi bật đoạn đã đi qua và điểm hiện tại
            chart.data.datasets[0].borderColor = flightData.coordinates.map((_, i) => i <= currentIndex ? '#FF0000' : '#888');
            chart.data.datasets[0].pointBackgroundColor = flightData.coordinates.map((_, i) => i === currentIndex ? '#FF0000' : '#888');
            chart.update();

            // Hiển thị thông tin thời gian, AGL, tốc độ, vario
            const showAgl = document.getElementById('show-agl').checked;
            const showSpeed = document.getElementById('show-speed').checked;
            const showVario = document.getElementById('show-vario').checked;
            let info = `Thời gian: ${flightData.times[currentIndex]}`;
            if (showAgl) info += ` | AGL: ${flightData.agl[currentIndex]} m`;
            if (showSpeed) info += ` | Tốc độ: ${flightData.speeds[currentIndex].toFixed(2)} km/h`;
            if (showVario) info += ` | Vario: ${flightData.varios[currentIndex].toFixed(2)} m/s`;
            document.getElementById('play-info').textContent = info;
            document.getElementById('time-display').textContent = flightData.times[currentIndex];

            // Tăng chỉ số và tiếp tục phát
            currentIndex++;
            if (currentIndex < flightData.coordinates.length) {
                animationFrame = setTimeout(playFlight, speed);
            } else {
                // Tự động pause khi hết chuyến bay
                document.getElementById('playPause').click();
            }
        }

        // Biểu đồ
        const ctx = document.getElementById('altitude-chart').getContext('2d');
        let chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: flightData.times,
                datasets: [{
                    label: 'Độ cao (m)',
                    data: flightData.altitudes,
                    borderColor: '#888',
                    pointBackgroundColor: '#888',
                    fill: true,
                    backgroundColor: 'rgba(0, 0, 255, 0.2)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        title: { display: true, text: 'Thời gian' },
                        ticks: {
                            callback: function(value, index, values) {
                                const time = flightData.times[index];
                                return time ? time : '';
                            }
                        }
                    },
                    y: { 
                        title: { display: true, text: 'Độ cao (m)' },
                        min: 0
                    }
                }
            }
        });

        function updateChart() {
            const chartType = document.getElementById('chart-type').value;
            chart.data.datasets[0].data = chartType === 'vario' ? flightData.varios : 
                                          chartType === 'speed' ? flightData.speeds : 
                                          flightData.altitudes;
            chart.data.datasets[0].label = chartType === 'vario' ? 'Vario (m/s)' : 
                                          chartType === 'speed' ? 'Tốc độ (km/h)' : 
                                          'Độ cao (m)';
            chart.options.scales.y.title.text = chartType === 'vario' ? 'Vario (m/s)' : 
                                               chartType === 'speed' ? 'Tốc độ (km/h)' : 
                                               'Độ cao (m)';
            chart.update();
        }

        function updateMapDisplay() {
            const hiresCheckbox = document.getElementById('hires-checkbox').checked;
            const satCheckbox = document.getElementById('sat-checkbox').checked;
            const airspaceCheckbox = document.getElementById('airspace-checkbox').checked;

            // Hi-res Tracklog
            if (hiresCheckbox) {
                map.setPaintProperty('flight-points', 'circle-radius', 5);
            } else {
                map.setPaintProperty('flight-points', 'circle-radius', 3);
            }

            // Sat Map
            if (satCheckbox) {
                map.setStyle('mapbox://styles/mapbox/satellite-v9');
            } else if (map.getStyle().name !== 'Streets') {
                map.setStyle('mapbox://styles/mapbox/streets-v11');
            }

            // Airspace
            if (airspaceCheckbox) {
                if (!map.getSource('airspace')) {
                    map.addSource('airspace', {
                        type: 'geojson',
                        data: {
                            type: 'FeatureCollection',
                            features: []
                        }
                    });
                    map.addLayer({
                        id: 'airspace',
                        type: 'fill',
                        source: 'airspace',
                        paint: {
                            'fill-color': '#00f',
                            'fill-opacity': 0.2
                        }
                    });
                }
            } else if (map.getLayer('airspace')) {
                map.removeLayer('airspace');
                map.removeSource('airspace');
            }
        }

        // Điều khiển nút Play
        const playPauseBtn = document.getElementById('playPause');
        const speedControls = document.getElementById('speedControls');
        const speedDisplay = document.getElementById('speedDisplay');
        const speedDownBtn = document.getElementById('speedDown');
        const speedUpBtn = document.getElementById('speedUp');
        let isPlaying = false;

        playPauseBtn.addEventListener('click', () => {
            isPlaying = !isPlaying;
            if (isPlaying) {
                playPauseBtn.textContent = '⏸';
                speedControls.classList.add('visible');
                playFlight();
            } else {
                playPauseBtn.textContent = '▶';
                speedControls.classList.remove('visible');
                clearTimeout(animationFrame);
                animationFrame = null;
                if (currentIndex >= flightData.coordinates.length) {
                    currentIndex = 0;
                    marker.setLngLat([flightData.coordinates[0].lng, flightData.coordinates[0].lat]);
                    chart.data.datasets[0].borderColor = '#888';
                    chart.data.datasets[0].pointBackgroundColor = '#888';
                    chart.update();
                    document.getElementById('play-info').textContent = '';
                    document.getElementById('time-display').textContent = '';
                }
            }
        });

        speedDownBtn.addEventListener('click', () => {
            if (speedIndex > 0) {
                speedIndex--;
                speed = speedLevels[speedIndex];
                speedDisplay.textContent = `${speed}x`;
            }
        });

        speedUpBtn.addEventListener('click', () => {
            if (speedIndex < speedLevels.length - 1) {
                speedIndex++;
                speed = speedLevels[speedIndex];
                speedDisplay.textContent = `${speed}x`;
            }
        });

        document.getElementById('chart-type').addEventListener('change', updateChart);

        initMap();
    </script>
</body>
</html>