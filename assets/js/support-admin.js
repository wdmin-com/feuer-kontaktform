document.addEventListener('DOMContentLoaded', function () {
  function slugify(value) {
    return String(value || '')
      .replace(/Ä/g, 'Ae')
      .replace(/Ö/g, 'Oe')
      .replace(/Ü/g, 'Ue')
      .replace(/ä/g, 'ae')
      .replace(/ö/g, 'oe')
      .replace(/ü/g, 'ue')
      .replace(/ß/g, 'ss')
      .replace(/&/g, ' und ')
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-{2,}/g, '-');
  }

  function bindSlugAutofill(scope) {
    var source = scope.querySelector('[data-slug-source]');
    var target = scope.querySelector('[data-slug-target]');

    if (!source || !target) {
      return;
    }

    function generatedValue() {
      return slugify(source.value);
    }

    function sync(force) {
      var generated = generatedValue();
      var mode = target.dataset.slugMode || '';

      if (force || mode !== 'manual' || target.value.trim() === '') {
        target.value = generated;
        target.dataset.slugMode = generated === '' ? '' : 'auto';
      }
    }

    var initialGenerated = generatedValue();
    if (target.value.trim() === '' || target.value === initialGenerated) {
      target.dataset.slugMode = target.value.trim() === '' ? '' : 'auto';
      sync(true);
    } else {
      target.dataset.slugMode = 'manual';
    }

    source.addEventListener('input', function () {
      sync(false);
    });

    target.addEventListener('input', function () {
      var generated = generatedValue();
      var current = target.value.trim();

      if (current === '') {
        target.dataset.slugMode = '';
        sync(false);
        return;
      }

      target.dataset.slugMode = current === generated ? 'auto' : 'manual';
    });
  }

  function syncRequired(row) {
    var hidden = row.querySelector('[data-required-hidden]');
    var checkbox = row.querySelector('[data-required-checkbox]');
    if (hidden && checkbox) {
      hidden.value = checkbox.checked ? '1' : '0';
    }
  }

  function bindRow(row) {
    var remove = row.querySelector('[data-remove-field]');
    var checkbox = row.querySelector('[data-required-checkbox]');
    var moveUp = row.querySelector('[data-move-field="up"]');
    var moveDown = row.querySelector('[data-move-field="down"]');

    if (remove) {
      remove.addEventListener('click', function () {
        row.remove();
      });
    }

    if (moveUp) {
      moveUp.addEventListener('click', function () {
        var previous = row.previousElementSibling;
        if (previous && previous.matches('[data-field-row]')) {
          row.parentNode.insertBefore(row, previous);
        }
      });
    }

    if (moveDown) {
      moveDown.addEventListener('click', function () {
        var next = row.nextElementSibling;
        if (next && next.matches('[data-field-row]')) {
          row.parentNode.insertBefore(next, row);
        }
      });
    }

    if (checkbox) {
      checkbox.addEventListener('change', function () {
        syncRequired(row);
      });
    }
  }

  function createRow() {
    var row = document.createElement('div');
    row.className = 'feuer-field-row';
    row.setAttribute('data-field-row', '1');
    row.innerHTML =
      '<input name="topic_fields[key][]" placeholder="key">' +
      '<input name="topic_fields[label][]" placeholder="Label">' +
      '<select name="topic_fields[type][]">' +
        '<option value="text">text</option>' +
        '<option value="email">email</option>' +
        '<option value="tel">tel</option>' +
        '<option value="textarea">textarea</option>' +
        '<option value="select">select</option>' +
        '<option value="file">file</option>' +
      '</select>' +
      '<select name="topic_fields[width][]">' +
        '<option value="full">full</option>' +
        '<option value="half">half</option>' +
        '<option value="third">third</option>' +
      '</select>' +
      '<input type="hidden" name="topic_fields[required][]" value="0" data-required-hidden>' +
      '<label><input type="checkbox" value="1" data-required-checkbox> Pflicht</label>' +
      '<input name="topic_fields[options][]" placeholder="Optionen bei select">' +
      '<div class="feuer-field-actions">' +
        '<button type="button" class="button" data-move-field="up">Hoch</button>' +
        '<button type="button" class="button" data-move-field="down">Runter</button>' +
        '<button type="button" class="button" data-remove-field>Entfernen</button>' +
      '</div>';
    bindRow(row);
    return row;
  }

  document.querySelectorAll('[data-field-row]').forEach(bindRow);

  document.querySelectorAll('[data-topic-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('[data-field-row]').forEach(syncRequired);
    });
  });

  document.querySelectorAll('.feuer-inline-form, [data-topic-form]').forEach(bindSlugAutofill);

  document.querySelectorAll('[data-add-field]').forEach(function (button) {
    button.addEventListener('click', function () {
      var form = button.closest('[data-topic-form]');
      var list = form ? form.querySelector('[data-fields-list]') : null;
      if (list) {
        list.appendChild(createRow());
      }
    });
  });

  document.querySelectorAll('[data-feuer-tabs]').forEach(function (tabs) {
    var buttons = Array.prototype.slice.call(tabs.querySelectorAll('[data-tab-target]')).filter(function (button) {
      return button.closest('[data-feuer-tabs]') === tabs;
    });
    var panels = Array.prototype.slice.call(tabs.querySelectorAll('[data-tab-panel]')).filter(function (panel) {
      return panel.closest('[data-feuer-tabs]') === tabs;
    });
    var storageKey = 'feuer-kontaktform-tab:' + window.location.pathname + window.location.search.split('&feuer-message=')[0] + ':' + buttons.map(function (button) {
      return button.getAttribute('data-tab-target') || '';
    }).join('|');

    function activate(target) {
      var hasTarget = buttons.some(function (button) {
        return button.getAttribute('data-tab-target') === target;
      });
      if (!hasTarget) {
        return;
      }
      buttons.forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-tab-target') === target);
      });
      panels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-tab-panel') === target);
      });
      try {
        window.localStorage.setItem(storageKey, target);
      } catch (error) {
        // localStorage can be unavailable in restricted browser modes.
      }
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        activate(button.getAttribute('data-tab-target'));
      });
    });

    try {
      activate(window.localStorage.getItem(storageKey) || '');
    } catch (error) {
      // Keep the server-rendered first tab active.
    }
  });

  document.querySelectorAll('[data-media-select]').forEach(function (button) {
    button.addEventListener('click', function () {
      var wrap = button.closest('.feuer-media-picker');
      var input = wrap ? wrap.querySelector('[data-media-url]') : null;
      var label = button.closest('label');
      var preview = label ? label.querySelector('[data-media-preview]') : null;
      var frame;

      if (!input || !window.wp || !window.wp.media) {
        return;
      }

      frame = window.wp.media({
        title: 'Logo auswählen',
        button: { text: 'Logo verwenden' },
        multiple: false
      });

      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        input.value = attachment.url || '';
        if (preview) {
          preview.src = input.value;
          preview.hidden = !input.value;
        }
      });

      frame.open();
    });
  });

  document.querySelectorAll('[data-media-clear]').forEach(function (button) {
    button.addEventListener('click', function () {
      var wrap = button.closest('.feuer-media-picker');
      var input = wrap ? wrap.querySelector('[data-media-url]') : null;
      var label = button.closest('label');
      var preview = label ? label.querySelector('[data-media-preview]') : null;
      if (input) {
        input.value = '';
      }
      if (preview) {
        preview.removeAttribute('src');
        preview.hidden = true;
      }
    });
  });
});
