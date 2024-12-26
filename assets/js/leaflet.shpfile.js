/* global L */

L.Shapefile = L.GeoJSON.extend({
  initialize: function(file, options) {
    L.GeoJSON.prototype.initialize.call(this, undefined, options);
    this.addFileData(file);
  },

  addFileData: function(file) {
    var self = this;
    if (typeof file !== 'string' && !('byteLength' in file)) {
      var data = self._isArrayBuffer(file) ? self._arrayBufferToBuffer(file) : file;
      self._convertToGeoJSON(data);
      return self;
    }
    self._loadFile(file)
      .then(function(buffer) {
        self._convertToGeoJSON(buffer);
      })
      .catch(function(err) {
        self.fire('data:error', { error: err });
      });
    return self;
  },

  _isArrayBuffer: function(obj) {
    return obj instanceof ArrayBuffer || obj.constructor.name === 'ArrayBuffer';
  },

  _arrayBufferToBuffer: function(arrayBuffer) {
    var buf = new Uint8Array(arrayBuffer);
    return buf;
  },

  _loadFile: function(file) {
    var self = this;
    return new Promise(function(resolve, reject) {
      if (typeof file === 'string') {
        fetch(file)
          .then(function(response) {
            return response.arrayBuffer();
          })
          .then(function(buffer) {
            resolve(self._arrayBufferToBuffer(buffer));
          })
          .catch(reject);
        return;
      }
      var reader = new FileReader();
      reader.onload = function() {
        resolve(self._arrayBufferToBuffer(reader.result));
      };
      reader.onerror = reject;
      reader.readAsArrayBuffer(file);
    });
  },

  _convertToGeoJSON: function(buffer) {
    var self = this;
    shp(buffer).then(function(geojson) {
      self.addData(geojson);
      self.fire('data:loaded');
    }).catch(function(err) {
      self.fire('data:error', { error: err });
    });
  }
}); 