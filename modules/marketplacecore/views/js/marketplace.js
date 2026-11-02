(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var nav = document.querySelector('[data-mp-nav]');
    if (nav) {
      document.documentElement.classList.add('mp-storefront');
      if (nav.parentElement !== document.body) {
        document.body.insertBefore(nav, document.body.firstChild);
      }
      var toggle = nav.querySelector('[data-mp-menu]');
      var links = nav.querySelector('.mp-nav-links');
      if (toggle && links) {
        toggle.addEventListener('click', function () {
          links.classList.toggle('hidden');
          links.classList.toggle('flex');
        });
      }
    }

    var home = document.querySelector('.mp-home');
    if (home && home.parentElement) {
      Array.prototype.forEach.call(home.parentElement.children, function (child) {
        if (child !== home && !child.contains(home)) {
          child.classList.add('mp-theme-hidden');
        }
      });
    }

    var catalog = document.querySelector('[data-mp-catalog]');
    if (catalog) {
      var cards = catalog.querySelectorAll('[data-mp-product]');
      var fields = catalog.querySelectorAll('[data-mp-filter]');
      var apply = function () {
        var query = (catalog.getAttribute('data-query') || '').toLowerCase();
        var selected = {};
        Array.prototype.forEach.call(fields, function (field) {
          selected[field.getAttribute('data-mp-filter')] = field.value;
          if (field.getAttribute('data-mp-filter') === 'q') {
            query = field.value.toLowerCase();
          }
        });
        Array.prototype.forEach.call(cards, function (card) {
          var visible = true;
          var haystack = (card.getAttribute('data-search') || '').toLowerCase();
          if (query && haystack.indexOf(query) === -1) {
            visible = false;
          }
          if (selected.brand && card.getAttribute('data-brand') !== selected.brand) {
            visible = false;
          }
          if (selected.vendor && (card.getAttribute('data-vendors') || '').indexOf(selected.vendor) === -1) {
            visible = false;
          }
          if (selected.category && card.getAttribute('data-category') !== selected.category) {
            visible = false;
          }
          if (selected.availability === 'in' && card.getAttribute('data-stock') === '0') {
            visible = false;
          }
          if (selected.availability === 'low' && card.getAttribute('data-low') !== '1') {
            visible = false;
          }
          var price = parseInt(card.getAttribute('data-price') || '0', 10);
          if (selected.price === 'under15' && price >= 1500) {
            visible = false;
          }
          if (selected.price === 'mid' && (price < 1500 || price > 3000)) {
            visible = false;
          }
          if (selected.price === 'over30' && price <= 3000) {
            visible = false;
          }
          card.hidden = !visible;
        });
      };
      Array.prototype.forEach.call(fields, function (field) {
        field.addEventListener('input', apply);
        field.addEventListener('change', apply);
      });
      apply();
      var filterToggle = catalog.querySelector('[data-mp-filters-toggle]');
      var filterPanel = catalog.querySelector('[data-mp-filters]');
      if (filterToggle && filterPanel) {
        filterToggle.addEventListener('click', function () {
          filterPanel.classList.toggle('hidden');
        });
      }
    }

    var drop = document.querySelector('[data-mp-drop]');
    if (drop) {
      var input = drop.querySelector('input[type="file"]');
      var label = drop.querySelector('[data-mp-file]');
      var mark = function (file) {
        if (label) {
          label.textContent = file ? file.name : 'CSV file, or click to browse';
        }
      };
      ['dragenter', 'dragover'].forEach(function (name) {
        drop.addEventListener(name, function (event) {
          event.preventDefault();
          drop.classList.add('is-over');
        });
      });
      ['dragleave', 'drop'].forEach(function (name) {
        drop.addEventListener(name, function (event) {
          event.preventDefault();
          drop.classList.remove('is-over');
        });
      });
      drop.addEventListener('drop', function (event) {
        if (!input || !event.dataTransfer.files.length) {
          return;
        }
        input.files = event.dataTransfer.files;
        mark(input.files[0]);
      });
      if (input) {
        input.addEventListener('change', function () {
          mark(input.files[0]);
        });
      }
    }

    document.querySelectorAll('[data-mp-chart]').forEach(function (host) {
      var values = [];
      try {
        values = JSON.parse(host.getAttribute('data-mp-chart') || '[]');
      } catch (error) {
        values = [];
      }
      if (!values.length) {
        host.textContent = 'No recorded orders yet.';
        return;
      }
      var kind = host.getAttribute('data-mp-kind') || 'line';
      var width = 640;
      var height = 180;
      var pad = 18;
      var max = Math.max.apply(null, values.concat([1]));
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
      if (kind === 'bar') {
        var gap = 10;
        var barWidth = Math.max(8, (width - pad * 2 - gap * (values.length - 1)) / values.length);
        values.forEach(function (value, index) {
          var barHeight = Math.max(4, (value / max) * (height - pad * 2));
          var rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
          rect.setAttribute('x', String(pad + index * (barWidth + gap)));
          rect.setAttribute('y', String(height - pad - barHeight));
          rect.setAttribute('width', String(barWidth));
          rect.setAttribute('height', String(barHeight));
          rect.setAttribute('rx', '6');
          rect.setAttribute('fill', '#2c4c8f');
          svg.appendChild(rect);
        });
      } else {
        var points = values.map(function (value, index) {
          var x = values.length === 1 ? width / 2 : pad + index * ((width - pad * 2) / (values.length - 1));
          var y = height - pad - (value / max) * (height - pad * 2);
          return x + ',' + y;
        }).join(' ');
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        line.setAttribute('points', points);
        line.setAttribute('fill', 'none');
        line.setAttribute('stroke', '#2c4c8f');
        line.setAttribute('stroke-width', '3');
        svg.appendChild(line);
        points.split(' ').forEach(function (pair) {
          var xy = pair.split(',');
          var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          dot.setAttribute('cx', xy[0]);
          dot.setAttribute('cy', xy[1]);
          dot.setAttribute('r', '5');
          dot.setAttribute('fill', '#2c4c8f');
          svg.appendChild(dot);
        });
      }
      host.textContent = '';
      host.appendChild(svg);
    });

    if (catalog) {
      var empty = catalog.querySelector('[data-mp-empty]');
      var cardsNow = catalog.querySelectorAll('[data-mp-product]');
      var paintEmpty = function () {
        if (!empty) {
          return;
        }
        var visible = false;
        Array.prototype.forEach.call(cardsNow, function (card) {
          if (!card.hidden) {
            visible = true;
          }
        });
        empty.hidden = visible;
      };
      catalog.addEventListener('input', paintEmpty);
      catalog.addEventListener('change', paintEmpty);
      paintEmpty();
    }

    document.querySelectorAll('[data-mp-embla]').forEach(function (root) {
      if (!window.EmblaCarousel) {
        return;
      }
      var viewport = root.querySelector('[data-mp-viewport]');
      var plugins = [];
      if (root.hasAttribute('data-mp-autoplay') && window.EmblaCarouselAutoplay) {
        plugins.push(window.EmblaCarouselAutoplay({ delay: 5200, stopOnInteraction: false }));
      }
      var embla = window.EmblaCarousel(viewport, {
        loop: root.hasAttribute('data-mp-loop'),
        align: 'start'
      }, plugins);
      var dots = root.querySelectorAll('[data-mp-dot]');
      var mark = function () {
        var selected = embla.selectedScrollSnap();
        Array.prototype.forEach.call(dots, function (dot, index) {
          if (index === selected) {
            dot.setAttribute('aria-current', 'true');
          } else {
            dot.removeAttribute('aria-current');
          }
        });
      };
      var prev = root.querySelector('[data-mp-prev]');
      var next = root.querySelector('[data-mp-next]');
      if (prev) {
        prev.addEventListener('click', function () { embla.scrollPrev(); });
      }
      if (next) {
        next.addEventListener('click', function () { embla.scrollNext(); });
      }
      Array.prototype.forEach.call(dots, function (dot, index) {
        dot.addEventListener('click', function () { embla.scrollTo(index); });
      });
      embla.on('select', mark);
      mark();
    });

    document.querySelectorAll('[data-mp-reveal]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-mp-reveal'));
        if (!input) {
          return;
        }
        var shown = input.type === 'password';
        input.type = shown ? 'text' : 'password';
        button.setAttribute('aria-pressed', shown ? 'true' : 'false');
      });
    });

    document.querySelectorAll('[data-mp-drawer]').forEach(function (button) {
      button.addEventListener('click', function () {
        var shell = document.querySelector('[data-mp-shell]');
        if (shell) {
          shell.classList.toggle('is-open');
        }
      });
    });
    document.querySelectorAll('[data-mp-collapse]').forEach(function (button) {
      button.addEventListener('click', function () {
        var shell = document.querySelector('[data-mp-shell]');
        if (shell) {
          shell.classList.toggle('is-collapsed');
        }
      });
    });

    if (window.lucide) {
      window.lucide.createIcons();
    }
  });
})();
