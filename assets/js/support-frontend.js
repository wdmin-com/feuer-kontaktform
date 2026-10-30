document.addEventListener('DOMContentLoaded', function () {
  function qsa(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function show(element, visible) {
    if (!element) {
      return;
    }
    element.hidden = !visible;
    element.classList.toggle('is-hidden', !visible);
  }

  function setMessage(form, text, type) {
    var box = form.querySelector('[data-message]');
    if (!box) {
      return;
    }
    box.textContent = text || '';
    box.className = 'feuer-support-message ' + (type || '');
    show(box, !!text);
  }

  function getConfig(form) {
    try {
      return JSON.parse(form.getAttribute('data-config') || '[]');
    } catch (error) {
      return [];
    }
  }

  function currentDepartment(form) {
    var id = parseInt(form.querySelector('[data-department-input]').value || '0', 10);
    return getConfig(form).find(function (department) {
      return parseInt(department.id, 10) === id;
    }) || null;
  }

  function currentTopic(form) {
    var department = currentDepartment(form);
    var id = parseInt(form.querySelector('[data-topic-input]').value || '0', 10);
    if (!department) {
      return null;
    }
    return (department.topics || []).find(function (topic) {
      return parseInt(topic.id, 10) === id;
    }) || null;
  }

  function optionButton(label, description) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'feuer-option';
    button.innerHTML = '<strong></strong><span></span>';
    button.querySelector('strong').textContent = label;
    button.querySelector('span').textContent = description || '';
    return button;
  }

  function renderDepartments(form) {
    var target = form.querySelector('[data-departments]');
    var input = form.querySelector('[data-department-input]');
    if (!target || !input) {
      return;
    }
    target.innerHTML = '';
    getConfig(form).forEach(function (department) {
      var button = optionButton(department.name, department.description || '');
      button.addEventListener('click', function () {
        input.value = department.id;
        qsa(target, '.feuer-option').forEach(function (item) { item.classList.remove('is-selected'); });
        button.classList.add('is-selected');
        form.querySelector('[data-topic-input]').value = '';
        renderTopics(form);
      });
      if (String(input.value) === String(department.id)) {
        button.classList.add('is-selected');
      }
      target.appendChild(button);
    });
  }

  function renderTopics(form) {
    var target = form.querySelector('[data-topics]');
    var input = form.querySelector('[data-topic-input]');
    var department = currentDepartment(form);
    if (!target || !input) {
      return;
    }
    target.innerHTML = '';
    if (!department) {
      return;
    }
    (department.topics || []).forEach(function (topic) {
      var button = optionButton(topic.title, '');
      button.addEventListener('click', function () {
        input.value = topic.id;
        qsa(target, '.feuer-option').forEach(function (item) { item.classList.remove('is-selected'); });
        button.classList.add('is-selected');
        renderFields(form);
      });
      if (String(input.value) === String(topic.id)) {
        button.classList.add('is-selected');
      }
      target.appendChild(button);
    });
    renderFields(form);
  }

  function fieldControl(form, field) {
    var wrap = document.createElement('label');
    var input;
    wrap.className = 'feuer-field width-' + (field.width || 'full');
    wrap.setAttribute('data-field-key', field.key);
    wrap.innerHTML = '<span></span><em></em>';
    wrap.querySelector('span').textContent = field.label + (field.required ? ' *' : '');

    if (field.type === 'textarea') {
      input = document.createElement('textarea');
      input.rows = 5;
    } else if (field.type === 'select') {
      input = document.createElement('select');
      input.innerHTML = '<option value="">Bitte auswählen</option>';
      (field.options || []).forEach(function (option) {
        var node = document.createElement('option');
        node.value = option;
        node.textContent = option;
        input.appendChild(node);
      });
    } else {
      input = document.createElement('input');
      input.type = field.type === 'file' ? 'file' : (field.type || 'text');
      if (field.type === 'file') {
        input.accept = (form.getAttribute('data-file-types') || '').split(/[\s,;]+/).filter(Boolean).map(function (type) {
          return '.' + type.replace(/[^a-z0-9]/gi, '').toLowerCase();
        }).join(',');
      }
    }

    input.name = 'fields[' + field.key + ']';
    input.setAttribute('data-required', field.required ? '1' : '0');
    wrap.insertBefore(input, wrap.querySelector('em'));
    return wrap;
  }

  function renderFields(form) {
    var target = form.querySelector('[data-fields]');
    var topic = currentTopic(form);
    if (!target) {
      return;
    }
    target.innerHTML = '';
    if (!topic) {
      return;
    }
    (topic.fields || []).forEach(function (field) {
      target.appendChild(fieldControl(form, field));
    });
  }

  function openSelection(form, departmentId, topicId) {
    var departmentInput = form.querySelector('[data-department-input]');
    var topicInput = form.querySelector('[data-topic-input]');
    var launcher = form.parentNode.querySelector('[data-contact-launcher]');
    if (!departmentInput || !topicInput) return;
    departmentInput.value = String(departmentId || '');
    topicInput.value = String(topicId || '');
    renderTopics(form);
    if (!topicInput.value) {
      var department = currentDepartment(form);
      topicInput.value = department && department.topics && department.topics[0] ? String(department.topics[0].id) : '';
      renderFields(form);
    }
    if (launcher) launcher.classList.add('is-complete');
    form.classList.remove('is-collapsed');
    showStep(form, topicInput.value ? 3 : 0);
    window.setTimeout(function () { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 30);
  }

  function panels(form) {
    return qsa(form, '.feuer-step');
  }

  function stepIndex(form) {
    return parseInt(form.getAttribute('data-step-index') || '0', 10);
  }

  function updateProgress(form) {
    var bar = form.querySelector('[data-progress-bar]');
    var total = panels(form).length - 1;
    var index = Math.min(stepIndex(form) + 1, total);
    if (bar) {
      var value = Math.max(8, Math.round((index / total) * 100)) + '%';
      if (form.closest('.feuer-design-rail')) {
        bar.style.height = value;
        bar.style.width = '100%';
      } else {
        bar.style.width = value;
        bar.style.height = '100%';
      }
    }
  }

  function showStep(form, index, direction) {
    var list = panels(form);
    index = Math.max(0, Math.min(index, list.length - 1));

    if (list[index] && list[index].getAttribute('data-skip') === '1') {
      showStep(form, index + (direction === 'back' ? -1 : 1), direction);
      return;
    }

    list.forEach(function (panel, panelIndex) {
      var active = panelIndex === index;
      panel.classList.toggle('is-active', active);
      show(panel, active);
    });
    form.setAttribute('data-step-index', String(index));
    updateProgress(form);
  }

  function validatePrivacy(form) {
    var choice = form.querySelector('input[name="privacy"]:checked');
    var denied = form.querySelector('[data-denied]');
    if (!choice) {
      setMessage(form, 'Bitte treffen Sie eine Auswahl zum Datenschutz.', 'error');
      return false;
    }
    if (choice.value === 'no') {
      show(denied, true);
      setMessage(form, 'Ohne Einwilligung können wir Ihre Anfrage nicht verarbeiten.', 'error');
      return false;
    }
    show(denied, false);
    return true;
  }

  function validateFields(form) {
    var valid = true;
    qsa(form, '[data-field-key]').forEach(function (wrap) {
      var input = wrap.querySelector('input, textarea, select');
      var error = wrap.querySelector('em');
      var value = input && input.type === 'file' ? (input.files.length ? input.files[0].name : '') : (input ? input.value.trim() : '');
      var maxMb = parseInt(form.getAttribute('data-max-file-mb') || '8', 10);
      var message = '';

      if (input && input.getAttribute('data-required') === '1' && !value) {
        message = 'Dieses Feld ist erforderlich.';
      } else if (input && input.type === 'email' && value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        message = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
      } else if (input && input.type === 'file' && input.files.length && input.files[0].size > maxMb * 1024 * 1024) {
        message = 'Die Datei ist zu groß. Maximal ' + maxMb + ' MB.';
      }

      wrap.classList.toggle('has-error', !!message);
      if (error) {
        error.textContent = message;
      }
      if (message) {
        valid = false;
      }
    });
    if (!valid) {
      setMessage(form, 'Bitte korrigieren Sie die markierten Felder.', 'error');
    }
    return valid;
  }

  function validateCurrent(form) {
    var panel = panels(form)[stepIndex(form)];
    var name = panel ? panel.getAttribute('data-step') : '';
    setMessage(form, '', '');

    if (name === 'privacy') {
      return validatePrivacy(form);
    }
    if (name === 'department' && !currentDepartment(form)) {
      setMessage(form, 'Bitte wählen Sie eine Abteilung aus.', 'error');
      return false;
    }
    if (name === 'topic' && !currentTopic(form)) {
      setMessage(form, 'Bitte wählen Sie ein Thema aus.', 'error');
      return false;
    }
    if (name === 'fields') {
      return validateFields(form);
    }
    if (name === 'captcha' && !form.querySelector('input[name="captcha_answer"]').value.trim()) {
      setMessage(form, 'Bitte beantworten Sie die Sicherheitsfrage.', 'error');
      return false;
    }
    return true;
  }

  function autoAdvancePrivacy(form) {
    window.setTimeout(function () {
      var panel = panels(form)[stepIndex(form)];
      if (!panel || panel.getAttribute('data-step') !== 'privacy') {
        return;
      }
      if (validatePrivacy(form)) {
        showStep(form, stepIndex(form) + 1);
      }
    }, 0);
  }

  function applyServerFieldErrors(form, errors) {
    Object.keys(errors || {}).forEach(function (key) {
      var wrap = form.querySelector('[data-field-key="' + key + '"]');
      if (!wrap) {
        return;
      }
      wrap.classList.add('has-error');
      var error = wrap.querySelector('em');
      if (error) {
        error.textContent = errors[key];
      }
    });
  }

  function submit(form) {
    var data = new FormData(form);
    var overlay = form.querySelector('[data-overlay]');
    setMessage(form, '', '');
    show(overlay, true);

    fetch(form.getAttribute('data-ajax-url'), {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    }).then(function (response) {
      return response.json();
    }).then(function (json) {
      var payload = json && json.data ? json.data : {};
      if (json && json.success) {
        form.querySelector('[data-success-text]').textContent = payload.message || 'Ihre Anfrage wurde gesendet.';
        showStep(form, panels(form).length - 1);
        return;
      }

      if (payload.captcha) {
        form.querySelector('input[name="captcha_token"]').value = payload.captcha.token;
        var captchaLabel = form.querySelector('[data-step="captcha"] .feuer-field span');
        if (captchaLabel) {
          captchaLabel.textContent = payload.captcha.question;
        }
        form.querySelector('input[name="captcha_answer"]').value = '';
      }
      applyServerFieldErrors(form, payload.fieldErrors || {});
      setMessage(form, payload.message || 'Die Anfrage konnte nicht gespeichert werden.', 'error');
    }).catch(function () {
      setMessage(form, 'Die Anfrage konnte nicht gespeichert werden.', 'error');
    }).finally(function () {
      show(overlay, false);
    });
  }

  qsa(document, '.feuer-support-form').forEach(function (form) {
    renderDepartments(form);
    renderTopics(form);
    showStep(form, 0);

    qsa(form, '[data-next]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (validateCurrent(form)) {
          showStep(form, stepIndex(form) + 1);
        }
      });
    });

    qsa(form, '[data-prev]').forEach(function (button) {
      button.addEventListener('click', function () {
        showStep(form, stepIndex(form) - 1, 'back');
      });
    });

    qsa(form, 'input[name="privacy"]').forEach(function (input) {
      input.addEventListener('change', function () {
        autoAdvancePrivacy(form);
      });
    });

    qsa(form, '.feuer-privacy-choice').forEach(function (button) {
      button.addEventListener('click', function () {
        autoAdvancePrivacy(form);
      });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (validateCurrent(form) && validateFields(form)) {
        submit(form);
      }
    });
  });

  qsa(document, '[data-contacts-directory]').forEach(function (directory) {
    var revealBox = directory.querySelector('[data-contact-reveal]');
    var currentId = '';
    qsa(directory, '[data-reveal-contact]').forEach(function (button) {
      button.addEventListener('click', function () { currentId = button.getAttribute('data-contact-id') || ''; revealBox.hidden = false; revealBox.querySelector('[data-reveal-answer]').focus(); });
    });
    var submit = revealBox ? revealBox.querySelector('[data-reveal-submit]') : null;
    if (submit) submit.addEventListener('click', function () {
      var body = new URLSearchParams(); body.set('action', 'feuer_contact_reveal'); body.set('nonce', directory.getAttribute('data-nonce') || ''); body.set('contact_id', currentId); body.set('captcha_token', directory.getAttribute('data-captcha-token') || ''); body.set('captcha_answer', revealBox.querySelector('[data-reveal-answer]').value); body.set('honeypot', revealBox.querySelector('[data-reveal-honeypot]').value);
      fetch(directory.getAttribute('data-ajax-url'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() }).then(function (response) { return response.json(); }).then(function (result) { var error = revealBox.querySelector('[data-reveal-error]'); if (!result.success) { error.textContent = result.data && result.data.message ? result.data.message : 'Sicherheitsprüfung fehlgeschlagen.'; return; } var card = directory.querySelector('[data-contact-id="' + currentId + '"]'); if (card) { var actions = card.closest('.feuer-contact-person').querySelector('.feuer-contact-actions'); var data = result.data || {}; actions.insertAdjacentHTML('beforeend', (data.email ? '<a class="button-secondary" href="mailto:' + encodeURIComponent(data.email) + '">E-Mail</a>' : '') + (data.phone ? '<a class="button-secondary" href="tel:' + encodeURIComponent(data.phone) + '">Telefon</a>' : '')); card.remove(); } revealBox.hidden = true; }).catch(function () { revealBox.querySelector('[data-reveal-error]').textContent = 'Die Sicherheitsprüfung konnte nicht ausgeführt werden.'; });
    });
  });

  qsa(document, '[data-contact-launcher]').forEach(function (launcher) {
    var form = launcher.parentNode.querySelector('.feuer-support-form');
    if (!form) return;
    var modal = launcher.querySelector('[data-contact-modal]');
    var modalOptions = modal ? modal.querySelector('[data-contact-modal-options]') : null;
    var config = getConfig(form);
    form.classList.add('is-collapsed');
    qsa(launcher, '[data-open-department]').forEach(function (button) {
      button.addEventListener('click', function () {
        var departmentId = button.getAttribute('data-open-department');
        var department = config.find(function (item) { return String(item.id) === String(departmentId); });
        if (!modal || !modalOptions || !department || !(department.topics || []).length) { openSelection(form, departmentId, ''); return; }
        modalOptions.innerHTML = '';
        (department.topics || []).forEach(function (topic) {
          var option = document.createElement('button');
          option.type = 'button';
          option.className = 'feuer-option';
          option.innerHTML = '<strong></strong><span></span>';
          option.querySelector('strong').textContent = topic.title || 'Thema';
          option.querySelector('span').textContent = 'Anfrage zu diesem Thema öffnen';
          option.addEventListener('click', function () { modal.hidden = true; openSelection(form, departmentId, topic.id); });
          modalOptions.appendChild(option);
        });
        modal.hidden = false;
        var close = modal.querySelector('[data-contact-modal-close]');
        if (close) close.focus();
      });
    });
    qsa(launcher, '[data-open-selection]').forEach(function (button) {
      button.addEventListener('click', function () { openSelection(form, button.getAttribute('data-department-id'), button.getAttribute('data-topic-id') || ''); });
    });
    qsa(launcher, '[data-contact-modal-close]').forEach(function (button) {
      button.addEventListener('click', function () { if (modal) modal.hidden = true; });
    });
    if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) modal.hidden = true; });
  });
});
