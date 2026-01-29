(function(){
  let data_el = document.getElementById("semski-api-data");
  var token = data_el.attributes['data-token'].value;
  var apiUrl = data_el.attributes['data-api-url'].value;
  var layers = ['layer0', 'layer1', 'layer2', 'layer3', 'layer4'];
  var currentLayer = 0;
  var pollInterval = null;

  function updateLayerStatus(layer, status, info) {
    var el = document.getElementById('ss-' + layer);
    if (!el) return;

    var statusEl = el.querySelector('.ss-layer-status');
    var infoEl = el.querySelector('.ss-layer-info');

    if (status === 'pending') {
      el.style.background = '#e9ecef';
      statusEl.textContent = '○';
    } else if (status === 'running') {
      el.style.background = '#fff3cd';
      statusEl.textContent = '◐';
    } else if (status === 'waiting') {
      el.style.background = '#cce5ff';
      statusEl.textContent = '⏳';
    } else if (status === 'done') {
      el.style.background = '#d4edda';
      statusEl.textContent = '✓';
    } else if (status === 'error') {
      el.style.background = '#f8d7da';
      statusEl.textContent = '✗';
    }

    if (info) {
      infoEl.textContent = info;
    }
  }

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

  function checkJobsAndProceed() {
    fetch(apiUrl + '?action=semanticschemas-install&step=status&format=json')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var status = data.status;
        var jobCount = status.pendingJobs || 0;

        document.getElementById('ss-job-count').textContent = jobCount;

        if (jobCount === 0) {
          document.getElementById('ss-jobs').style.display = 'none';
          clearInterval(pollInterval);
          pollInterval = null;

          // Mark current layer as done before moving on
          updateLayerStatus(layers[currentLayer], 'done', 'Complete');

          // Move to next layer
          currentLayer++;
          if (currentLayer < layers.length) {
            runLayer(layers[currentLayer]);
          } else {
            showResult(true, '<strong>Installation Complete!</strong>' +
              '<br>All layers have been installed successfully.');
          }
        } else {
          document.getElementById('ss-jobs').style.display = 'block';
          updateLayerStatus(layers[currentLayer], 'waiting',
            'Waiting for ' + jobCount + ' jobs...');
        }
      })
      .catch(function(err) {
        console.error('Status check failed:', err);
      });
  }

  function runLayer(layer) {
    updateLayerStatus(layer, 'running', 'Installing...');

    var formData = new FormData();
    formData.append('action', 'semanticschemas-install');
    formData.append('step', layer);
    formData.append('token', token);
    formData.append('format', 'json');

    fetch(apiUrl, {
      method: 'POST',
      body: formData
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.error) {
          updateLayerStatus(layer, 'error', data.error.info || 'Error');
          showResult(false, '<strong>Installation Failed</strong><br>' + (data.error.info || 'Unknown error'));
          return;
        }

        var install = data.install;
        // Check for errors array instead of success boolean (MW API quirk)
        if (install.errors && install.errors.length > 0) {
          updateLayerStatus(layer, 'error', install.errors.join(', '));
          showResult(false, '<strong>Installation Failed</strong><br>' +
            install.errors.join(', '));
          return;
        }

        var created = 0, updated = 0;
        for (let key in install.created) {
          created += (install.created[key] || []).length;
        }
        for (let key in install.updated) {
          updated += (install.updated[key] || []).length;
        }

        updateLayerStatus(layer, 'done', 'Created: ' + created + ', Updated: ' + updated);

        // Check for pending jobs
        if (install.pendingJobs > 0) {
          document.getElementById('ss-jobs').style.display = 'block';
          document.getElementById('ss-job-count').textContent = install.pendingJobs;
          updateLayerStatus(layer, 'waiting', 'Waiting for ' + install.pendingJobs + ' jobs...');

          // Start polling for job completion
          pollInterval = setInterval(checkJobsAndProceed, 2000);
        } else {
          // Proceed immediately to next layer
          currentLayer++;
          if (currentLayer < layers.length) {
            setTimeout(function() { runLayer(layers[currentLayer]); }, 500);
          } else {
            showResult(true, '<strong>Installation Complete!</strong>' +
              '<br>All layers have been installed successfully.');
          }
        }
      })
      .catch(function(err) {
        updateLayerStatus(layer, 'error', 'Network error');
        showResult(false, '<strong>Installation Failed</strong><br>Network error: ' + err.message);
      });
  }

  document.getElementById('ss-start-btn').addEventListener('click', function() {
    this.disabled = true;
    this.textContent = 'Installing...';
    document.getElementById('ss-progress').style.display = 'block';

    currentLayer = 0;
    runLayer(layers[0]);
  });
})();