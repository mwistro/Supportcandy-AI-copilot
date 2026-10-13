(function () {
  'use strict';

  var button = document.getElementById('cai-regenerate-pending');
  if (!button) return;
  var status = document.getElementById('cai-regenerate-status');
  var errors = document.getElementById('cai-regenerate-errors');

  async function request(id) {
    var body = new URLSearchParams({ action: 'cai_regenerate_pending', nonce: caiRegenerate.nonce });
    if (id !== undefined) body.set('draft_id', id);
    var response;
    var payload;
    try {
      response = await fetch(caiRegenerate.url, { method: 'POST', credentials: 'same-origin', body: body });
      payload = await response.json();
    } catch (error) {
      var connectionError = new Error('No se pudo confirmar el resultado. Recarga el PWA para comprobarlo antes de reintentar.');
      connectionError.stop = true;
      throw connectionError;
    }
    if (!response.ok || !payload || !payload.success) {
      var failure = new Error(payload && payload.data && payload.data.message || 'No se pudo completar la peticion.');
      failure.stop = response.status === 403 || response.status === 400 || response.status === 429;
      throw failure;
    }
    return payload.data;
  }

  button.addEventListener('click', async function () {
    if (button.disabled) return;
    button.disabled = true;
    errors.textContent = '';
    status.textContent = 'Consultando los borradores pendientes...';
    var updated = 0;
    var skipped = 0;
    var failed = 0;
    var processed = 0;
    var total = 0;
    try {
      var batch = await request();
      if (!batch || !Array.isArray(batch.ids)) throw new Error('Lista de pendientes no valida. Recarga la pagina.');
      total = batch.ids.length;
      if (!total) {
        status.textContent = 'No hay borradores pendientes para regenerar.';
        return;
      }
      for (var i = 0; i < total; i++) {
        status.textContent = 'Regenerando ' + (i + 1) + ' de ' + total + '. Actualizados: ' + updated + '. Fallos: ' + failed + '.';
        try {
          var result = await request(batch.ids[i]);
          if (result.result === 'updated') updated++;
          else if (result.result === 'skipped') skipped++;
          else throw new Error('Resultado no reconocido. Comprueba el borrador en el PWA.');
        } catch (error) {
          failed++;
          var item = document.createElement('li');
          item.textContent = 'Borrador #' + batch.ids[i] + ': ' + error.message;
          errors.appendChild(item);
          if (error.stop) {
            processed++;
            break;
          }
        }
        processed++;
      }
      status.textContent = (processed === total ? 'Proceso terminado. ' : 'Proceso interrumpido. ') +
        'Actualizados: ' + updated + '. Omitidos: ' + skipped + '. Fallos o sin confirmar: ' + failed +
        '. Sin procesar: ' + (total - processed) + '. Recarga el PWA para ver las respuestas.';
    } catch (error) {
      status.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
}());
