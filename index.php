<?php

// Hàm tính khoảng cách Haversine
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// Chuyển đổi tọa độ IGC sang độ thập phân
function parseIGCCoordinates($coord) {
    $isLatitude = (strlen($coord) == 8);
    $degrees = (int)substr($coord, 0, $isLatitude ? 2 : 3);
    $minutes = (float)substr($coord, $isLatitude ? 2 : 3, 5) / 100000;
    $decimal = $degrees + ($minutes * 100 / 60);
    return ($coord[strlen($coord) - 1] == 'S' || $coord[strlen($coord) - 1] == 'W') ? -$decimal : $decimal;
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
        'turnpoints' => []
    ];

    if (!file_exists($filePath)) {
        die("File IGC không tồn tại!");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $prevLat = $prevLon = null;

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

            if ($prevLat !== null && $prevLon !== null) {
                $flightData['distance'] += calculateDistance($prevLat, $prevLon, $latDecimal, $lonDecimal);
            }
            $prevLat = $latDecimal;
            $prevLon = $lonDecimal;

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

// Hiển thị thông tin chi tiết với bản đồ
function displayFlightDetail($flightData) {
    $apiKey = 'AIzaSyDnE3bCwhzy4tJ22BVmRMyolwuyCx-1rQc'; // Thay bằng API Key thực tế
    $coordinatesJson = json_encode($flightData['coordinates']);
    $takeoffLat = $flightData['coordinates'][0]['lat'] ?? 21.27405; // Lấy tọa độ từ dòng đầu tiên
    $takeoffLon = $flightData['coordinates'][0]['lng'] ?? 105.38372;

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Flight Detail</title>';
    echo '<style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .flight-detail { max-width: 800px; margin: 0 auto; }
            h1 { font-size: 24px; margin-bottom: 5px; }
            h1 em { font-size: 16px; color: #666; }
            .summary { font-size: 16px; margin: 10px 0; }
            .details { border-top: 1px solid #ccc; padding-top: 10px; }
            .details table { width: 100%; border-collapse: collapse; }
            .details th, .details td { padding: 8px; text-align: left; border-bottom: 1px solid #eee; }
            .details th { width: 30%; font-weight: normal; color: #666; }
            #map { height: 400px; width: 100%; margin-top: 20px; }
            .error { color: red; }
        </style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="flight-detail">';
    echo '<h1>Flight Detail <em>' . htmlspecialchars($flightData['pilot']) . ' · ' . $flightData['date'] . ' · ' . number_format($flightData['distance'], 2) . ' km</em></h1>';
    echo '<div class="summary">PARAGLIDING ⛳ <span id="takeoff-location">Đang tải...</span> ∷ ⌛ ' . $flightData['duration'] . ' h ∷ ø ' . number_format($flightData['avg_speed'], 2) . ' km/h ∷ ⊺ ' . $flightData['max_altitude'] . ' m</div>';
    echo '<div id="map"></div>';
    echo '<div class="details">';
    echo '<table>';
    echo '<tr><th>Pilot</th><td>' . htmlspecialchars($flightData['pilot']) . '</td></tr>';
    echo '<tr><th>Date</th><td>' . $flightData['date'] . '</td></tr>';
    echo '<tr><th>Takeoff Time</th><td>' . $flightData['takeoff_time'] . ' (UTC+7)</td></tr>';
    echo '<tr><th>Landing Time</th><td>' . $flightData['landing_time'] . ' (UTC+7)</td></tr>';
    echo '<tr><th>Flight Duration</th><td>' . $flightData['duration'] . ' h</td></tr>';
    echo '<tr><th>Distance</th><td>' . number_format($flightData['distance'], 2) . ' km</td></tr>';
    echo '<tr><th>Average Speed</th><td>' . number_format($flightData['avg_speed'], 2) . ' km/h</td></tr>';
    echo '<tr><th>Max Altitude</th><td>' . $flightData['max_altitude'] . ' m</td></tr>';
    echo '<tr><th>Glider</th><td>' . htmlspecialchars($flightData['glider']) . '</td></tr>';
    echo '<tr><th>Hardware</th><td>' . htmlspecialchars($flightData['hardware']) . '</td></tr>';
    echo '<tr><th>Software</th><td>' . htmlspecialchars($flightData['software']) . '</td></tr>';
    echo '<tr><th>Turnpoints</th><td>';
    foreach ($flightData['turnpoints'] as $i => $tp) {
        if ($i > 0 && $i < count($flightData['turnpoints']) - 1) {
            echo number_format($tp['lat'], 5) . ' N, ' . number_format($tp['lon'], 5) . ' E (' . htmlspecialchars($tp['desc']) . ')<br>';
        }
    }
    echo '</td></tr>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo "<script>
            function initMap() {
                console.log('Initializing map with takeoff: $takeoffLat, $takeoffLon');
                console.log('Coordinates:', $coordinatesJson);
                const map = new google.maps.Map(document.getElementById('map'), {
                    center: { lat: $takeoffLat, lng: $takeoffLon },
                    zoom: 12
                });
                const flightPathCoordinates = $coordinatesJson;
                if (flightPathCoordinates.length > 0) {
                    const flightPath = new google.maps.Polyline({
                        path: flightPathCoordinates,
                        geodesic: true,
                        strokeColor: '#FF0000',
                        strokeOpacity: 1.0,
                        strokeWeight: 2
                    });
                    flightPath.setMap(map);
                    const bounds = new google.maps.LatLngBounds();
                    flightPathCoordinates.forEach(coord => bounds.extend(coord));
                    map.fitBounds(bounds);
                }
                const geocoder = new google.maps.Geocoder();
                geocoder.geocode({ location: { lat: $takeoffLat, lng: $takeoffLon } }, (results, status) => {
                    console.log('Geocode Status:', status, 'Results:', results);
                    if (status === 'OK' && results[0]) {
                        document.getElementById('takeoff-location').innerText = results[0].formatted_address;
                    } else {
                        document.getElementById('takeoff-location').innerText = 'Unknown location';
                    }
                });
            }
            </script>";
    echo "<script src='https://maps.googleapis.com/maps/api/js?key=$apiKey&libraries=geometry&callback=initMap' async defer></script>";
    echo '</body>';
    echo '</html>';
}

// Sử dụng file IGC
$filePath = 'flight.igc'; // Thay bằng đường dẫn thực tế
$flightData = parseIGCFile($filePath);
displayFlightDetail($flightData);

?>

