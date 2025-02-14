# LCD County Map
## Interactive GIS Map for Lewis County Voting Precincts

A WordPress plugin that provides an interactive GIS map interface for displaying Lewis County voting precincts and election results.

### Features

- Interactive precinct map using Leaflet.js
- GIS data integration for accurate precinct boundaries
- Integration with LCD Election Results plugin
- Admin interface for managing GIS data files
- Shortcode support for embedding maps
- Real-time precinct search functionality
- Responsive design for all screen sizes

### Installation

1. Upload the `lcd-county-map` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Upload your GIS data files through the admin interface (County Map > Settings)

### Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- Write permissions for the `gis-precincts` directory
- Compatible GIS data files (Shapefiles in ZIP format)

### Usage

#### Basic Map Shortcode
```
[lcd_precinct_map]
```

#### Map with Custom Dimensions
```
[lcd_precinct_map height="800px" width="100%"]
```

#### Election Results Map
```
[lcd_election_map]
```

### Directory Structure

```
lcd-county-map/
├── assets/
│   ├── css/         # Stylesheet files
│   └── js/          # JavaScript files including Leaflet integration
├── gis-precincts/   # Directory for GIS data storage
│   ├── precincts_ref/
│   └── voting_ref/
├── includes/
│   ├── class-election-integration.php  # Election results integration
│   └── shapefile.inc.php              # Shapefile processing
└── lcd-county-map.php                 # Main plugin file
```

### GIS Data Requirements

The plugin expects GIS data in the following format:
- Zipped Shapefile format (.shp, .dbf, .prj, etc.)
- Two main data types:
  1. Precinct boundaries (precincts.zip)
  2. Voting data (voting.zip)

### Integration with LCD Election Results

This plugin integrates seamlessly with the LCD Election Results plugin to display:
- Election results by precinct
- Color-coded precincts based on voting data
- Interactive data visualization

### Administration

Access the plugin settings through:
WordPress Admin > County Map

Features available in the admin interface:
- Upload and manage GIS data files
- View file upload history
- Manage map display settings

### Shortcode Parameters

| Parameter | Description | Default | Example |
|-----------|-------------|---------|---------|
| height | Map height | 600px | height="800px" |
| width | Map width | 100% | width="80%" |
| show_election_data | Show election results | false | show_election_data="true" |

### Support

For technical support or feature requests, please contact the LCD development team.

### License

This plugin is licensed under GPL v2 or later. 