document.addEventListener('DOMContentLoaded', function() {
    // Check if map container exists before initializing
    const mapContainer = document.getElementById('lcd-precinct-map');
    if (!mapContainer) {
        return; // Exit if map container is not found
    }

    // Initialize the map centered on Lewis County, WA with a closer zoom
    var map = L.map('lcd-precinct-map').setView([46.5, -122.6],9);
    var selectedLayer = null;
    var precinctData = {};
    var boundaryLayer = null;
    var activeDistricts = new Set();  // Track active districts
    var precinctLayers = {}; // Store precinct layers by precinct number

    // Define colors for legislative districts
    var districtColors = {
        19: '#ff7f00',  // Orange
        20: '#377eb8',  // Blue
        35: '#4daf4a'   // Green
    };

    // Define the default county view for reuse
    const defaultView = {
        center: [46.5, -122.6],
        zoom: 9
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
        
        // Highlight corresponding list item
        highlightPrecinctListItem(layer.feature.properties.PRECINCT_N);
    }

    // Reset highlight
    function resetHighlight(e) {
        var layer = e.target;
        if (layer !== selectedLayer && boundaryLayer) {
            boundaryLayer.resetStyle(layer);
            // Close popup if this isn't the selected layer
            if (layer !== selectedLayer) {
                layer.closePopup();
            }
        }
        info.update();
        
        // Reset list item highlight if not selected
        if (layer !== selectedLayer) {
            resetPrecinctListItemHighlight(layer.feature.properties.PRECINCT_N);
        }
    }

    // Select feature
    function selectFeature(e) {
        var layer = e.target;
        
        // If clicking the already selected layer, deselect it
        if (selectedLayer === layer) {
            boundaryLayer.resetStyle(selectedLayer);
            selectedLayer = null;
            info.update();
            // Clear list selection
            document.querySelectorAll('.lcd-precinct-item').forEach(item => {
                item.classList.remove('active');
            });
            // Close any open popup
            layer.closePopup();
            map.closePopup();
            // Reset zoom to show full county
            map.setView(defaultView.center, defaultView.zoom, {
                animate: true,
                duration: 0.5
            });
            return;
        }
        
        // If there's a previously selected layer, reset its style
        if (selectedLayer && selectedLayer !== layer && boundaryLayer) {
            boundaryLayer.resetStyle(selectedLayer);
            // Reset previous list item
            resetPrecinctListItemHighlight(selectedLayer.feature.properties.PRECINCT_N);
            // Close any open popup
            selectedLayer.closePopup();
            map.closePopup();
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
        
        // Highlight list item
        selectPrecinctListItem(precNum);
        
        // Ensure the selected item is visible in the list
        const listItem = document.querySelector(`.lcd-precinct-item[data-precinct="${precNum}"]`);
        if (listItem) {
            listItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    // Process voting data
    function processVotingData(feature) {
        const precNum = feature.properties.PRECINCT_N;
        // Skip precinct #0
        if (precNum === '0' || precNum === 0) return;
        
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
        // Skip precinct #0
        if (feature.properties.PRECINCT_N === '0' || feature.properties.PRECINCT_N === 0) return;

        layer.on({
            mouseover: highlightFeature,
            mouseout: resetHighlight,
            click: selectFeature
        });

        const precNum = feature.properties.PRECINCT_N;
        precinctLayers[precNum] = layer;
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

    // Populate precinct list
    function populatePrecinctList() {
        const precinctList = document.querySelector('.lcd-precinct-list');
        if (!precinctList) return;

        // Sort precincts by number
        const sortedPrecincts = Object.entries(precinctData)
            .sort((a, b) => parseInt(a[0]) - parseInt(b[0]));

        // Create and append list items
        sortedPrecincts.forEach(([precNum, data]) => {
            const item = document.createElement('div');
            item.className = 'lcd-precinct-item';
            item.dataset.precinct = precNum;
            item.textContent = `#${precNum} ${data.name}`;
            
            item.addEventListener('click', () => {
                const layer = precinctLayers[precNum];
                if (layer) {
                    // Trigger the click event on the map layer
                    layer.fire('click');
                    // Pan to the precinct
                    map.fitBounds(layer.getBounds(), {
                        padding: [50, 50],
                        maxZoom: 12
                    });
                }
            });
            
            item.addEventListener('mouseover', () => {
                const layer = precinctLayers[precNum];
                if (layer) {
                    layer.fire('mouseover');
                }
            });
            
            item.addEventListener('mouseout', () => {
                const layer = precinctLayers[precNum];
                if (layer) {
                    layer.fire('mouseout');
                }
            });
            
            precinctList.appendChild(item);
        });
    }

    // Handle precinct search
    function setupPrecinctSearch() {
        const searchInput = document.getElementById('precinct-search');
        const clearButton = document.querySelector('.search-clear');
        if (!searchInput || !clearButton) return;

        function updateClearButton() {
            clearButton.classList.toggle('visible', searchInput.value.length > 0);
        }

        function clearSearch() {
            searchInput.value = '';
            updateClearButton();
            // Show all items
            document.querySelectorAll('.lcd-precinct-item').forEach(item => {
                item.style.display = '';
            });
            searchInput.focus();
        }

        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            const items = document.querySelectorAll('.lcd-precinct-item');
            
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
            
            updateClearButton();
        });

        clearButton.addEventListener('click', clearSearch);

        // Clear on Escape key
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                clearSearch();
            }
        });

        // Initial state
        updateClearButton();
    }

    // Highlight precinct list item
    function highlightPrecinctListItem(precNum) {
        const item = document.querySelector(`.lcd-precinct-item[data-precinct="${precNum}"]`);
        if (item && !item.classList.contains('active')) {
            item.classList.add('hover');
        }
    }

    // Reset precinct list item highlight
    function resetPrecinctListItemHighlight(precNum) {
        const item = document.querySelector(`.lcd-precinct-item[data-precinct="${precNum}"]`);
        if (item && !item.classList.contains('active')) {
            item.classList.remove('hover');
        }
    }

    // Select precinct list item
    function selectPrecinctListItem(precNum) {
        // Remove active class from all items
        document.querySelectorAll('.lcd-precinct-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // Add active class to selected item
        const item = document.querySelector(`.lcd-precinct-item[data-precinct="${precNum}"]`);
        if (item) {
            item.classList.add('active');
        }
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

        // Populate the precinct list after data is loaded
        populatePrecinctList();
        setupPrecinctSearch();

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
            // Close any open popups
            map.closePopup();
            // Reset to default view
            map.setView(defaultView.center, defaultView.zoom, {
                animate: true,
                duration: 0.5
            });
            // Clear list selection
            document.querySelectorAll('.lcd-precinct-item').forEach(item => {
                item.classList.remove('active');
            });
        }
    });

    // Handle zoom events to force refresh of layers with new offset calculations
    map.on('zoomend', function() {
        // Let Leaflet handle the coordinate transformations naturally
        // No need to refresh layers on zoom
    });
}); 