(function () {
  function mountSummary() {
    var block = document.querySelector('#mp-result [data-mp-summary]');
    var slot = document.getElementById('mp-summary');
    if (block && slot) {
      slot.innerHTML = '';
      slot.appendChild(block);
    }
  }

  mountSummary();
  document.querySelectorAll('[data-mp-step]').forEach(function (step) {
    step.addEventListener('click', function () {
      var form = step.closest('form');
      var input = form && form.querySelector('[name="quantity"]');
      if (!input) {
        return;
      }
      var next = Math.max(1, (parseInt(input.value, 10) || 1) + parseInt(step.getAttribute('data-mp-step'), 10));
      input.value = String(next);
      var submit = form.querySelector('[name="submitQty"]');
      if (submit && form.requestSubmit) {
        form.requestSubmit(submit);
      }
    });
  });
  var button = document.querySelector('[data-mp-optimize]');
  if (!button) {
    return;
  }

  button.addEventListener('click', function (event) {
    var form = button.closest('form');
    if (!form || !window.fetch) {
      return;
    }
    event.preventDefault();
    var target = document.getElementById('mp-result');
    if (target) {
      target.innerHTML = '<p class="mp-loading">Evaluating valid vendor allocations...</p>';
    }
    var body = new FormData(form);
    body.set('ajax', '1');
    body.set('action', 'optimize');
    body.set('submitOptimize', '1');
    fetch(form.action, {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      if (!target) {
        return;
      }
      if (!payload.ok) {
        target.textContent = payload.error || 'Optimization failed.';
        return;
      }
      target.innerHTML = payload.html;
      mountSummary();
    }).catch(function () {
      form.submit();
    });
  });
})();
