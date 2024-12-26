document.addEventListener('DOMContentLoaded', function() {
    // Initialize the map centered on Lewis County, WA
    var map = L.map('lcd-precinct-map').setView([46.5, -122.6], 9);
    var selectedLayer = null;
    var precinctData = {};
    var boundaryLayer = null;
    var activeDistricts = new Set();  // Track active districts

    // Define colors for legislative districts
    var districtColors = {
        19: '#ff7f00',  // Orange
        20: '#377eb8',  // Blue
        35: '#4daf4a'   // Green
    };

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Create info control
    var info = L.control();
    info.onAdd = function(map) {
        this._div = L.DomUtil.create('div', 'info');
        this.update();
        return this._div;
    };
    info.update = function(props) {
        this._div.innerHTML = '<h4>Lewis County Precinct</h4>' + 
            (props ? '<p><strong>#' + props.PRECINCT_N + '</strong> ' + props.PRECINCT + '</p>'
            : 'Hover over a precinct');
    };
    info.addTo(map);
    
    // Make info control globally accessible
    window.lcdMapInfo = info;

    // Create legend control
    var legend = L.control({position: 'bottomright'});
    legend.onAdd = function(map) {
        var div = L.DomUtil.create('div', 'info legend');
        div.innerHTML = '<h4>Legislative Districts</h4>';
        Array.from(activeDistricts).sort().forEach(function(district) {
            if (districtColors[district]) {
                div.innerHTML += 
                    '<div style="margin: 5px 0;">' +
                    '<i style="background: ' + districtColors[district] + '; ' +
                    'display: inline-block; width: 18px; height: 18px; margin-right: 8px; opacity: 0.7;"></i>' +
                    'District ' + district +
                    '</div>';
            }
        });
        return div;
    };

    // Style for the precincts
    function style(feature) {
        const precNum = feature.properties.PRECINCT_N;
        const data = precinctData[precNum] || {};
        const district = data.legislativeDistrict || feature.properties.LEGISLATIV;
        return {
            fillColor: districtColors[district] || '#999999',
            weight: 2,
            opacity: 1,
            color: '#666',
            fillOpacity: 0.5
        };
    }

    // Highlight feature
    function highlightFeature(e) {
        var layer = e.target;
        if (layer !== selectedLayer) {
            const precNum = layer.feature.properties.PRECINCT_N;
            const data = precinctData[precNum] || {};
            const district = data.legislativeDistrict || layer.feature.properties.LEGISLATIV;
            layer.setStyle({
                fillColor: districtColors[district] || '#999999',
                weight: 3,
                opacity: 1,
                color: '#666',
                fillOpacity: 0.8
            });
        }
        info.update(layer.feature.properties);
    }

    // Reset highlight
    function resetHighlight(e) {
        var layer = e.target;
        if (layer !== selectedLayer && boundaryLayer) {
            boundaryLayer.resetStyle(layer);
        }
        info.update();
    }

    // Select feature
    function selectFeature(e) {
        var layer = e.target;
        
        // If there's a previously selected layer, reset its style
        if (selectedLayer && selectedLayer !== layer && boundaryLayer) {
            boundaryLayer.resetStyle(selectedLayer);
        }

        // Set the new selected layer
        selectedLayer = layer;
        
        // Apply selected style
        const precNum = layer.feature.properties.PRECINCT_N;
        const data = precinctData[precNum] || {};
        const district = data.legislativeDistrict || layer.feature.properties.LEGISLATIV;
        layer.setStyle({
            fillColor: districtColors[district] || '#999999',
            weight: 3,
            opacity: 1,
            color: '#666',
            fillOpacity: 0.9
        });
    }

    // Process voting data
    function processVotingData(feature) {
        const precNum = feature.properties.PRECINCT_N;
        const district = feature.properties.LEGISLATIV;
        if (!precinctData[precNum]) {
            precinctData[precNum] = {
                name: feature.properties.PRECINCT,
                population: feature.properties.POPULATION,
                legislativeDistrict: district
            };
            // Add district to active set if it exists
            if (district && districtColors[district]) {
                activeDistricts.add(district);
            }
        }
    }

    // Bind events to boundary layer
    function onEachFeature(feature, layer) {
        layer.on({
            mouseover: highlightFeature,
            mouseout: resetHighlight,
            click: selectFeature
        });

        const precNum = feature.properties.PRECINCT_N;
        const data = precinctData[precNum] || {};

        // Bind popup with feature properties
        var popupContent = '<h4>Precinct Information</h4>';
        popupContent += "<p><strong>#" + precNum + "</strong> " + (data.name || feature.properties.PRECINCT) + "</p>";
        if (data.population) {
            popupContent += "<p><strong>Population:</strong> " + data.population.toLocaleString() + "</p>";
        }
        if (data.legislativeDistrict) {
            popupContent += "<p><strong>Legislative District:</strong> " + data.legislativeDistrict + "</p>";
        }
        layer.bindPopup(popupContent);
    }

    // Custom shapefile loader with coordinate transformation
    function loadShapefile(url, options) {
        return new L.Shapefile(url, {
            ...options,
            // Create a custom pane for the shapefile with a transformed position
            pane: 'shapefilePane'
        });
    }

    // Create a custom pane for the shapefile layers with a CSS transform
    map.createPane('shapefilePane');
    const pane = map.getPane('shapefilePane');
    pane.style.position = 'relative';
    pane.style.top = '30px';  // Adjust this value to move down
    pane.style.zIndex = 400;  // Ensure it's above the base map

    // Function to refresh a shapefile layer
    function refreshShapefileLayer(layer) {
        if (layer && layer._map) {
            const center = map.getCenter();
            const zoom = map.getZoom();
            map.removeLayer(layer);
            map.addLayer(layer);
            map.setView(center, zoom, { animate: false });
        }
    }

    // Load voting data first
    var votingLayer = loadShapefile(lcdMapData.votingDataPath, {
        onEachFeature: function(feature, layer) {
            processVotingData(feature);
            onEachFeature(feature, layer);
        }
    });

    // Handle voting data load completion
    votingLayer.on('data:loaded', function() {
        console.log('Voting data loaded, creating boundary layer');
        
        // Add legend after we know which districts are active
        legend.addTo(map);
        
        // Create boundary layer
        boundaryLayer = loadShapefile(lcdMapData.boundaryPath, {
            style: style,
            onEachFeature: onEachFeature,
            filter: function(feature) {
                return feature.properties 
                    && feature.properties.PRECINCT_N 
                    && feature.properties.PRECINCT_N !== '0' 
                    && feature.properties.PRECINCT_N !== 0;
            }
        });

        // Add the boundary layer to the map
        boundaryLayer.addTo(map);

        // Handle boundary layer errors
        boundaryLayer.on('data:error', function(error) {
            console.error('Error loading boundary data:', error);
            var errorDiv = document.createElement('div');
            errorDiv.className = 'map-error';
            errorDiv.textContent = 'Error loading map data. Please try again later.';
            document.getElementById('lcd-precinct-map').appendChild(errorDiv);
        });

        boundaryLayer.on('data:loaded', function() {
            console.log('Boundary data loaded successfully');
        });

        // Dispatch event to notify other plugins
        var event = new CustomEvent('lcdMapLayersCreated', {
            detail: {
                boundaryLayer: boundaryLayer,
                votingLayer: votingLayer
            }
        });
        document.dispatchEvent(event);
    });

    // Handle voting data errors
    votingLayer.on('data:error', function(error) {
        console.error('Error loading voting data:', error);
    });

    // Add click handler to map to deselect feature when clicking outside
    map.on('click', function(e) {
        if (selectedLayer && boundaryLayer) {
            boundaryLayer.resetStyle(selectedLayer);
            selectedLayer = null;
            info.update(); // Clear the info when deselecting
        }
    });

    // Handle zoom events to force refresh of layers with new offset calculations
    map.on('zoomend', function() {
        // Let Leaflet handle the coordinate transformations naturally
        // No need to refresh layers on zoom
    });
}); 