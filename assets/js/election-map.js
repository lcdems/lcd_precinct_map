jQuery(document).ready(function($) {
    console.log('Election map extension loaded');

    // Simple initialization - wait for the map container to exist in DOM
    function init() {
        var mapContainer = $('#lcd-precinct-map');
        if (!mapContainer.length) {
            console.log('Map container not found, waiting...');
            setTimeout(init, 500);
            return;
        }

        console.log('Map container found, adding controls');
        
        // Create wrapper div
        var wrapper = $('<div class="lcd-map-wrapper"></div>');
        mapContainer.wrap(wrapper);
        
        // Create sidebar
        var sidebar = $('<div class="lcd-sidebar"></div>');
        
        // Create and add the controls
        var filters = $('<div class="lcd-election-filters"></div>');
        
        // Add title for filters
        filters.append('<h4>Select Election and Race</h4>');
        
        // Election date filter
        var dateSelect = $('<select id="election-date-filter"><option value="">Select Election Date</option></select>');
        lcdElectionData.elections.forEach(function(date, index) {
            // Add one day to correct for UTC conversion
            var displayDate = new Date(date);
            displayDate.setDate(displayDate.getDate() + 1);
            
            dateSelect.append(
                $('<option></option>')
                    .val(date)
                    .text(displayDate.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    }))
            );
            // Select the first election by default
            if (index === 0) {
                dateSelect.val(date);
            }
        });
        filters.append(dateSelect);

        // Race filter
        var raceSelect = $('<select id="race-filter"><option value="">View Total Votes</option></select>');
        filters.append(raceSelect);

        // Add loading spinner container
        var spinner = $('<div class="lcd-loading-spinner" style="display: none;"><div class="spinner"></div></div>');
        filters.append(spinner);
        
        // Add legend container
        var legend = $('<div id="lcd-election-legend" class="lcd-election-legend"></div>');
        
        // Add all elements to sidebar
        sidebar.append(filters);
        sidebar.append(legend);
        
        // Add sidebar before map
        mapContainer.before(sidebar);

        // Update races when election date changes
        $('#election-date-filter').on('change', function() {
            var selectedDate = $(this).val();
            raceSelect.empty().append('<option value="">View Total Votes</option>');
            
            if (selectedDate) {
                var races = lcdElectionData.races.filter(function(race) {
                    return race.election_date === selectedDate;
                });
                
                races.forEach(function(race) {
                    raceSelect.append(
                        $('<option></option>')
                            .val(race.race_name)
                            .text(race.race_name)
                    );
                });
                showVotesHeatmap(selectedDate);
            }
        });

        // Bind change events for fetching results
        $('#race-filter').on('change', function() {
            var election = $('#election-date-filter').val();
            var race = $(this).val();

            if (!race) {
                showVotesHeatmap(election);
                return;
            }

            // Show loading spinner
            $('.lcd-loading-spinner').show();

            // Fetch specific race results
            $.ajax({
                url: lcd_election_map.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_election_results',
                    election_date: election,
                    race_name: race,
                    nonce: lcd_election_map.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateMap(response.data);
                        updateLegend(response.data);
                    } else {
                        console.error('Failed to fetch election results:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', { status, error, xhr });
                },
                complete: function() {
                    // Hide loading spinner
                    $('.lcd-loading-spinner').hide();
                }
            });
        });

        // Trigger initial race load for the default selected election
        $('#election-date-filter').trigger('change');
    }

    function showVotesHeatmap(electionDate) {
        if (!window.lcdMapLayers || !window.lcdMapLayers.boundary) {
            setTimeout(() => showVotesHeatmap(electionDate), 500);
            return;
        }

        // Show loading spinner
        $('.lcd-loading-spinner').show();

        // Fetch vote totals for the election
        $.ajax({
            url: lcd_election_map.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_election_votes',
                election_date: electionDate,
                nonce: lcd_election_map.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateVotesDisplay(response.data, electionDate);
                } else {
                    console.error('Failed to fetch vote data:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', { status, error, xhr });
            },
            complete: function() {
                // Hide loading spinner
                $('.lcd-loading-spinner').hide();
            }
        });
    }

    function updateVotesDisplay(votesData, electionDate) {
        // Filter out the -1 (total) row for map display
        var precinctData = Object.fromEntries(
            Object.entries(votesData).filter(([key]) => key !== '-1')
        );
        
        // Find max votes for scaling (excluding the total row)
        var maxVotes = Math.max(...Object.values(precinctData).map(d => d.votes));
        // Round up to nearest 100 for cleaner scale
        maxVotes = Math.ceil(maxVotes / 100) * 100;

        // Create color scale for votes
        var colorScale = d3.scaleSequential()
            .domain([0, maxVotes])
            .interpolator(d3.interpolateBlues);

        // Update map features
        window.lcdMapLayers.boundary.eachLayer(function(layer) {
            var precinctNumber = layer.feature.properties.PRECINCT_N;
            var precinctName = layer.feature.properties.PRECINCT;
            var data = precinctData[precinctNumber] || { 
                votes: 0, 
                active_voters: 0,
                inactive_voters: 0,
                total_registered: 0
            };
            
            var baseStyle = {
                fillColor: colorScale(data.votes),
                fillOpacity: 0.7,
                color: '#666',
                weight: 1
            };

            // Set initial style
            layer.setStyle(baseStyle);

            // Store styles and data for event handling
            layer._votesData = {
                baseStyle: baseStyle,
                hoverStyle: {
                    ...baseStyle,
                    weight: 3,
                    color: '#333',
                    fillOpacity: 0.9
                },
                votes: data.votes,
                active_voters: data.active_voters,
                inactive_voters: data.inactive_voters,
                total_registered: data.total_registered,
                turnout: data.active_voters > 0 ? 
                    (data.votes / data.active_voters) * 100 : 0,
                precinctName: precinctName
            };

            // Add event handlers
            layer.off(); // Remove existing handlers
            layer.on({
                mouseover: function(e) {
                    this.setStyle(this._votesData.hoverStyle);
                    this.bringToFront();
                    if (window.lcdMapInfo) {
                        window.lcdMapInfo.update(layer.feature.properties);
                    }
                },
                mouseout: function(e) {
                    this.setStyle(this._votesData.baseStyle);
                    if (window.lcdMapInfo) {
                        window.lcdMapInfo.update();
                    }
                },
                click: function(e) {
                    var data = this._votesData;
                    var content = '<div class="precinct-popup">';
                    content += '<h4>' + data.precinctName + ' (#' + precinctNumber + ')</h4>';
                    
                    // Voter Registration Section
                    content += '<div class="voter-registration">';
                    content += '<h5>Voter Registration</h5>';
                    content += '<p><strong>Active Voters:</strong> ' + data.active_voters.toLocaleString() + '</p>';
                    content += '<p><strong>Inactive Voters:</strong> ' + data.inactive_voters.toLocaleString() + '</p>';
                    content += '<p><strong>Total Registered:</strong> ' + data.total_registered.toLocaleString() + '</p>';
                    content += '</div>';

                    // Voting Results Section
                    content += '<div class="voting-results">';
                    content += '<h5>Voting Results</h5>';
                    content += '<p><strong>Total Votes Cast:</strong> ' + data.votes.toLocaleString() + '</p>';
                    content += '<p><strong>Turnout:</strong> ' + data.turnout.toFixed(1) + '%<br>';
                    content += '<small>(percentage of active voters who voted)</small></p>';
                    content += '</div>';

                    content += '</div>';

                    L.popup()
                        .setLatLng(e.latlng)
                        .setContent(content)
                        .openOn(e.target._map);
                }
            });
        });

        // Update legend
        updateVotesLegend(votesData, electionDate, maxVotes);
    }

    function updateVotesLegend(votesData, electionDate, maxVotes) {
        var legend = $('#lcd-election-legend');
        legend.empty();

        // Calculate totals - exclude the -1 (total) row to avoid double-counting
        var totalVotes = 0;
        var totalActiveVoters = 0;
        var totalInactiveVoters = 0;
        
        // Check if we have official totals
        var hasOfficialTotals = votesData['-1'] !== undefined;
        
        if (hasOfficialTotals) {
            // Use official totals from the -1 row
            totalVotes = votesData['-1'].votes;
            // Sum voter registration from actual precincts only
            Object.entries(votesData).forEach(([precinctNum, data]) => {
                if (precinctNum !== '-1') {
                    totalActiveVoters += data.active_voters || 0;
                    totalInactiveVoters += data.inactive_voters || 0;
                }
            });
        } else {
            // Fallback: sum all precincts if no official totals
            Object.values(votesData).forEach(data => {
                totalVotes += data.votes;
                totalActiveVoters += data.active_voters || 0;
                totalInactiveVoters += data.inactive_voters || 0;
            });
        }
        
        var totalRegistered = totalActiveVoters + totalInactiveVoters;
        var voterTurnout = totalActiveVoters > 0 ? (totalVotes / totalActiveVoters) * 100 : 0;

        // Adjust date display
        var displayDate = new Date(electionDate);
        displayDate.setDate(displayDate.getDate() + 1);

        // Create legend content
        var content = '<div class="race-info">';
        content += '<h4>Results Summary</h4>';
        content += '<p><em>Election Date: ' + 
            displayDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }) + '</em></p>';
        
        // Voter Registration Statistics
        content += '<div class="voter-statistics">';
        content += '<h5>Voter Registration</h5>';
        content += '<p><strong>Active Voters:</strong> ' + totalActiveVoters.toLocaleString() + '</p>';
        content += '<p><strong>Inactive Voters:</strong> ' + totalInactiveVoters.toLocaleString() + '</p>';
        content += '<p><strong>Total Registered:</strong> ' + totalRegistered.toLocaleString() + '</p>';
        content += '</div>';

        // Voting Results Statistics
        content += '<div class="voter-statistics">';
        content += '<h5>Voting Results</h5>';
        content += '<p><strong>Total Votes Cast:</strong> ' + totalVotes.toLocaleString();
        if (hasOfficialTotals) {
            content += ' <span style="font-size: 0.85em; color: #28a745;" title="Using official totals from election data">✓ Official</span>';
        }
        content += '</p>';
        content += '<p><strong>Voter Turnout:</strong> ' + voterTurnout.toFixed(1) + '%<br>';
        content += '<small>(percentage of active voters who voted)</small></p>';
        content += '</div>';

        // Add color scale with rounded numbers
        content += '<h4>Vote Count Scale</h4><div class="turnout-scale">';
        // Create evenly spaced scale points
        var scalePoints = [0, 0.2, 0.4, 0.6, 0.8, 1].map(percent => 
            Math.round(maxVotes * percent / 100) * 100
        );
        scalePoints.forEach(function(value, index) {
            content += '<div class="scale-item">';
            content += '<div class="scale-color" style="background-color: ' + 
                d3.interpolateBlues(index / (scalePoints.length - 1)) + '"></div>';
            content += '<span>' + value.toLocaleString() + '</span>';
            content += '</div>';
        });
        content += '</div>';

        legend.html(content);

        // Add some CSS for the new sections
        if (!document.getElementById('lcd-voter-stats-style')) {
            const style = document.createElement('style');
            style.id = 'lcd-voter-stats-style';
            style.textContent = `
                .voter-statistics {
                    margin: 15px 0;
                    padding: 10px;
                    background: #f8f9fa;
                    border-radius: 4px;
                }
                .voter-statistics h5 {
                    margin: 0 0 10px 0;
                    color: #495057;
                }
                .voter-statistics p {
                    margin: 5px 0;
                }
                .voter-statistics small {
                    color: #6c757d;
                    font-size: 0.85em;
                }
                .precinct-popup {
                    min-width: 250px;
                }
                .precinct-popup h5 {
                    margin: 10px 0 5px 0;
                    color: #495057;
                }
                .voter-registration, .voting-results {
                    margin: 10px 0;
                    padding: 8px;
                    background: #f8f9fa;
                    border-radius: 4px;
                }
            `;
            document.head.appendChild(style);
        }
    }

    function resetMap() {
        if (!window.lcdMapLayers || !window.lcdMapLayers.boundary) {
            return;
        }

        window.lcdMapLayers.boundary.setStyle({
            fillColor: '#FFFFFF',
            fillOpacity: 0.7
        });

        $('#lcd-election-legend').empty();
    }

    function updateMap(results) {
        if (!window.lcdMapLayers || !window.lcdMapLayers.boundary) {
            setTimeout(function() { updateMap(results); }, 500);
            return;
        }

        // Process results to get total votes per precinct and winning candidate
        // Skip the "Total" row (precinct_number = -1) as it's not a geographic precinct
        var precinctData = {};
        Object.keys(results).forEach(function(precinctNumber) {
            // Skip the total row - it's not a real precinct
            if (precinctNumber === '-1') {
                return;
            }
            
            var precinct = results[precinctNumber];
            var totalVotes = 0;
            var winner = { votes: 0, party: null };

            precinct.candidates.forEach(function(candidate) {
                // Ensure votes is treated as a number
                var votes = parseInt(candidate.votes, 10);
                totalVotes += votes;
                if (votes > winner.votes) {
                    winner = {
                        votes: votes,
                        party: candidate.party,
                        name: candidate.name
                    };
                }
            });

            precinctData[precinctNumber] = {
                totalVotes: totalVotes,
                winner: winner,
                results: precinct
            };
        });

        // Find max total votes for scaling
        var maxVotes = Math.max(...Object.values(precinctData).map(d => d.totalVotes));

        // Create opacity scale
        var opacityScale = d3.scaleLinear()
            .domain([0, maxVotes])
            .range([0.3, 0.9]);

        // Remove existing event handlers from all layers
        window.lcdMapLayers.boundary.eachLayer(function(layer) {
            layer.off('mouseover mouseout click');
            layer.unbindPopup();  // Unbind any existing popups
        });

        // Update map features
        window.lcdMapLayers.boundary.eachLayer(function(layer) {
            var precinctNumber = layer.feature.properties.PRECINCT_N;
            var data = precinctData[precinctNumber];
            
            if (data) {
                var color = data.winner.party ? 
                    (lcdElectionData.partyColors[data.winner.party] || '#CCCCCC') : 
                    '#CCCCCC';

                var baseStyle = {
                    fillColor: color,
                    fillOpacity: opacityScale(data.totalVotes),
                    color: '#666',
                    weight: 1
                };

                // Set initial style
                layer.setStyle(baseStyle);

                // Store styles and data for event handling
                layer._electionData = {
                    baseStyle: baseStyle,
                    hoverStyle: {
                        ...baseStyle,
                        weight: 3,
                        color: '#333'
                    },
                    results: data.results
                };

                // Add event handlers
                layer.on({
                    mouseover: function(e) {
                        this.setStyle(this._electionData.hoverStyle);
                        this.bringToFront();
                        // Update info control
                        if (window.lcdMapInfo) {
                            window.lcdMapInfo.update(layer.feature.properties);
                        }
                    },
                    mouseout: function(e) {
                        this.setStyle(this._electionData.baseStyle);
                        // Reset info control
                        if (window.lcdMapInfo) {
                            window.lcdMapInfo.update();
                        }
                    },
                    click: function(e) {
                        if (!this._electionData) return;
                        
                        // Create popup content
                        var content = '<h4>' + this._electionData.results.precinct_name + ' (#' + precinctNumber + ')</h4>';
                        
                        // Add population and turnout info
                        var population = parseInt(this._electionData.results.registered_voters, 10);
                        var turnoutPercentage = population > 0 ? 
                            ((data.totalVotes / population) * 100).toFixed(1) : 0;
                        
                        content += '<p>';
                        content += '<strong>Registered Voters:</strong> ' + population.toLocaleString() + '<br>';
                        content += '<strong>Total Votes:</strong> ' + data.totalVotes.toLocaleString() + '<br>';
                        content += '<strong>Turnout:</strong> ' + turnoutPercentage + '%';
                        content += '</p>';
                        
                        content += '<table class="precinct-results">';
                        content += '<tr><th></th><th>Candidate</th><th>Votes</th><th>%</th></tr>';
                        
                        this._electionData.results.candidates
                            .sort((a, b) => parseInt(b.votes, 10) - parseInt(a.votes, 10))
                            .forEach(function(candidate) {
                                var votes = parseInt(candidate.votes, 10);
                                var percentage = ((votes / data.totalVotes) * 100).toFixed(1);
                                var candidateColor = candidate.party ? 
                                    (lcdElectionData.partyColors[candidate.party] || '#CCCCCC') : 
                                    '#CCCCCC';
                                
                                content += '<tr>';
                                content += '<td><div class="party-color-square" style="background-color: ' + candidateColor + ';" title="' + (candidate.party || 'No Party') + '"></div></td>';
                                content += '<td>' + candidate.name + '</td>';
                                content += '<td>' + votes.toLocaleString() + '</td>';
                                content += '<td>' + percentage + '%</td>';
                                content += '</tr>';
                            });
                        
                        content += '</table>';

                        // Create and open popup
                        L.popup()
                            .setLatLng(e.latlng)
                            .setContent(content)
                            .openOn(e.target._map);
                    }
                });
            } else {
                var baseStyle = {
                    fillColor: '#FFFFFF',
                    fillOpacity: 0.7,
                    color: '#666',
                    weight: 1
                };

                // Set initial style
                layer.setStyle(baseStyle);

                // Store styles for event handling
                layer._electionData = {
                    baseStyle: baseStyle,
                    hoverStyle: {
                        ...baseStyle,
                        weight: 3,
                        color: '#333'
                    }
                };

                // Add event handlers
                layer.on({
                    mouseover: function(e) {
                        this.setStyle(this._electionData.hoverStyle);
                    },
                    mouseout: function(e) {
                        this.setStyle(this._electionData.baseStyle);
                    }
                });
            }

            // Add info panel handlers last, so they don't get overwritten
            layer.on({
                mouseover: function(e) {
                    if (window.info) {
                        window.info.update(layer.feature.properties);
                    }
                },
                mouseout: function(e) {
                    if (window.info) {
                        window.info.update();
                    }
                }
            });
        });
    }

    function updateLegend(results) {
        var legend = $('#lcd-election-legend');
        legend.empty();

        // Get selected race name
        var raceName = $('#race-filter option:selected').text();
        var displayDate = new Date($('#election-date-filter').val());
        displayDate.setDate(displayDate.getDate() + 1);
        var electionDate = displayDate.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        // Check if we have official totals (precinct_number = -1)
        var officialTotals = results['-1'];
        var totalVotes = 0;
        var totalPopulation = 0;
        var candidateResults = [];

        if (officialTotals) {
            // Use official totals from the CSV (precinct_number = -1)
            officialTotals.candidates.forEach(function(candidate) {
                var votes = parseInt(candidate.votes, 10);
                totalVotes += votes;
                candidateResults.push({
                    name: candidate.name,
                    party: candidate.party || 'No Party',
                    votes: votes
                });
            });
            
            // Still sum population from actual precincts (not from totals row)
            Object.entries(results).forEach(function([precinctNum, precinct]) {
                if (precinctNum !== '-1') {
                    totalPopulation += parseInt(precinct.registered_voters || 0, 10);
                }
            });
        } else {
            // Fallback: Calculate totals from individual precincts if no official totals
            var candidateVotes = {};
            Object.values(results).forEach(function(precinct) {
                totalPopulation += parseInt(precinct.registered_voters || 0, 10);
                precinct.candidates.forEach(function(candidate) {
                    var votes = parseInt(candidate.votes, 10);
                    if (!candidateVotes[candidate.name]) {
                        candidateVotes[candidate.name] = {
                            party: candidate.party || 'No Party',
                            votes: 0
                        };
                    }
                    candidateVotes[candidate.name].votes += votes;
                });
            });
            
            // Convert to array
            Object.entries(candidateVotes).forEach(function([name, data]) {
                totalVotes += data.votes;
                candidateResults.push({
                    name: name,
                    party: data.party,
                    votes: data.votes
                });
            });
        }

        // Create race info section
        var content = '<div class="race-info">';
        content += '<h4>' + raceName + '</h4>';
        content += '<p><strong>Election Date:</strong> ' + electionDate + '</p>';
        content += '<p><strong>Total Votes:</strong> ' + totalVotes.toLocaleString();
        if (officialTotals) {
            content += ' <span style="font-size: 0.85em; color: #28a745;" title="Using official totals from election data">✓ Official</span>';
        }
        content += '</p>';
        content += "<p><strong>Total Population:</strong> " + totalPopulation.toLocaleString() + "<br/>(includes all ages and voting status)</p>";
        var turnoutPercentage = totalPopulation > 0 ? ((totalVotes / totalPopulation) * 100).toFixed(1) : 0;
        content += '<p><strong>Overall Turnout:</strong> ' + turnoutPercentage + '%</p>';
        content += '</div>';

        // Add results by candidate
        content += '<h4>Results</h4><ul>';
        candidateResults
            .sort((a, b) => b.votes - a.votes)
            .forEach(function(candidate) {
                var percentage = ((candidate.votes / totalVotes) * 100).toFixed(1);
                var color = candidate.party !== 'No Party' ? 
                    (lcdElectionData.partyColors[candidate.party] || '#CCCCCC') : 
                    '#CCCCCC';
                
                content += '<li>';
                content += '<div class="party-info">';
                content += '<div class="party-color-square" style="background-color: ' + color + ';" title="' + candidate.party + '"></div>';
                content += '<span>' + candidate.name;
                if (candidate.party !== 'No Party') {
                    content += ' (' + candidate.party + ')';
                }
                content += '</span>';
                content += '</div>';
                content += '<div>';
                content += '<strong>' + candidate.votes.toLocaleString() + '</strong> (' + percentage + '%)';
                content += '</div>';
                content += '</li>';
            });
        content += '</ul>';

        legend.html(content);
    }

    // Add CSS for party color squares
    const style = document.createElement('style');
    style.textContent = `
        .party-color-square {
            display: inline-block;
            width: 12px;
            height: 12px;
            margin-right: 5px;
            border: 1px solid #666;
            vertical-align: middle;
        }
        .precinct-results {
            border-collapse: collapse;
            margin: 10px 0;
        }
        .precinct-results th,
        .precinct-results td {
            padding: 4px 8px;
            border: 1px solid #ddd;
        }
        .turnout-info {
            margin-top: 10px;
            font-style: italic;
        }
        .lcd-clear-elections {
            padding: 5px 15px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .lcd-clear-elections:hover {
            background: #d32f2f;
        }
    `;
    document.head.appendChild(style);

    // Start initialization
    init();
}); 