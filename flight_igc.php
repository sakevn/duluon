<?php
// Kiểm tra ID chuyến bay
$flightId = isset($_GET['id']) ? intval($_GET['id']) : 5247853;
$apiKey = "03ECF5952EB046AC-A53195E89B7996E4-D1B128E82C3E2A66";
$apiUrl = "https://www.xcontest.org/api/flight/$flightId/?key=$apiKey";

// Gọi API để lấy dữ liệu chuyến bay
function getFlightData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    return json_decode($response, true);
}

$flightData = getFlightData($apiUrl);
if (!$flightData) {
    die("Không thể lấy dữ liệu chuyến bay. Vui lòng kiểm tra ID hoặc API Key.");
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết chuyến bay</title>
    <link rel="stylesheet" href="https://d393ilck4xazzy.cloudfront.net/widget/flight-map/2.1.1/bundle.css">
    <style>
        #map {
            width: 100%;
            height: 500px;
            position: relative;
        }
        .mapboxgl-ctrl-play {
            position: absolute;
            bottom: 10px;
            left: 10px;
            display: flex;
            align-items: center;
            background: #fff;
            border: 2px solid rgba(0, 0, 0, 0.2);
            border-radius: 4px;
            padding: 5px;
            z-index: 1000;
        }
        .mapboxgl-ctrl-play button {
            border: none;
            background: none;
            padding: 8px;
            cursor: pointer;
            font-size: 16px;
        }
        .speed-display {
            margin: 0 10px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div id="map"></div>
    <div class="mapboxgl-ctrl-play">
        <button id="playPause">▶</button>
        <span id="speedDisplay" class="speed-display">20x</span>
        <button id="decreaseSpeed">-</button>
        <button id="increaseSpeed">+</button>
    </div>

    <script type="module">
        import app from "https://d393ilck4xazzy.cloudfront.net/widget/flight-map/2.1.1/bundle.js";
        window.app = app;
        app.setApi("api", { league: "world", volume: "2025" });
        app.addFlight(<?php echo $flightId; ?>);
        app.adjustToFlightBounds();

        const playPauseBtn = document.getElementById("playPause");
        const speedDisplay = document.getElementById("speedDisplay");
        const decreaseSpeedBtn = document.getElementById("decreaseSpeed");
        const increaseSpeedBtn = document.getElementById("increaseSpeed");
        
        let isPlaying = false;
        let speedIndex = 4; // Bắt đầu từ 20ms
        const speeds = [1, 2, 5, 10, 20, 50, 100];
        
        function updateSpeedDisplay() {
            speedDisplay.textContent = speeds[speedIndex] + "x";
        }
        
        playPauseBtn.addEventListener("click", () => {
            isPlaying = !isPlaying;
            if (isPlaying) {
                playPauseBtn.textContent = "⏸ - " + speeds[speedIndex] + "x +";
            } else {
                playPauseBtn.textContent = "▶";
            }
        });
        
        decreaseSpeedBtn.addEventListener("click", () => {
            if (speedIndex > 0) {
                speedIndex--;
                updateSpeedDisplay();
                if (isPlaying) {
                    playPauseBtn.textContent = "⏸ - " + speeds[speedIndex] + "x +";
                }
            }
        });
        
        increaseSpeedBtn.addEventListener("click", () => {
            if (speedIndex < speeds.length - 1) {
                speedIndex++;
                updateSpeedDisplay();
                if (isPlaying) {
                    playPauseBtn.textContent = "⏸ - " + speeds[speedIndex] + "x +";
                }
            }
        });
    </script>
</body>
</html>
