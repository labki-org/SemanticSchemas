(function(){
  var data_el = document.getElementById("semski-api-data");
  var token = data_el.attributes['data-token'].value;
  var apiUrl = data_el.attributes['data-api-url'].value;

  function showResult(success, message) {
    var resultEl = document.getElementById('ss-result');
    resultEl.style.display = 'block';
    resultEl.innerHTML = '<div style="padding: 1em; border-radius: 4px; background: ' +
      (success ? '#d4edda' : '#f8d7da') + ';">' + message + '</div>';

    if (success) {
      resultEl.innerHTML += '<p style="margin-top: 1em;"><a href="' +
        mw.config.get('wgScript') + '/Special:SemanticSchemas" ' +
        'class="mw-ui-button mw-ui-progressive">Return to Overview</a></p>';
    }
  }

  document.getElementById('ss-start-btn').addEventListener('click', function() {
    this.disabled = true;
    this.textContent = 'Installing...';
    document.getElementById('ss-progress').style.display = 'block';

    var formData = new FormData();
    formData.append('action', 'semanticschemas-install');
    formData.append('step', 'install');
    formData.append('token', token);
    formData.append('format', 'json');

    fetch(apiUrl, {
      method: 'POST',
      body: formData
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        document.getElementById('ss-progress').style.display = 'none';

        if (data.error) {
          showResult(false, '<strong>Installation Failed</strong><br>' +
            (data.error.info || 'Unknown error'));
          return;
        }

        var install = data.install;
        if (install.errors && install.errors.length > 0) {
          showResult(false, '<strong>Installation Failed</strong><br>' +
            install.errors.join(', '));
          return;
        }

        var created = 0, updated = 0;
        for (var key in install.created) {
          created += (install.created[key] || []).length;
        }
        for (var key in install.updated) {
          updated += (install.updated[key] || []).length;
        }

        showResult(true, '<strong>Installation Complete!</strong>' +
          '<br>Created: ' + created + ', Updated: ' + updated);
      })
      .catch(function(err) {
        document.getElementById('ss-progress').style.display = 'none';
        showResult(false, '<strong>Installation Failed</strong><br>Network error: ' + err.message);
      });
  });
})();
