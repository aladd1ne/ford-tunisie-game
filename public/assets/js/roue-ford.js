/**
 * La Roue Ford — animation de la roue.
 *
 * Ce script ne décide jamais du résultat : il demande au backend Symfony de
 * trancher, puis fait tourner la roue jusqu'au secteur du lot renvoyé. Aucun
 * identifiant de lot n'est envoyé par le navigateur.
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var FALLBACK_COLORS = ['#00095B', '#066FEF', '#1B2A4A', '#4D7DF2', '#8FB4F7', '#C9DCFB'];
    var RIM_RADIUS = 96;
    var HUB_RADIUS = 44; // garde les libellés hors de la zone du logo central (cf. .wheel-hub)
    var LABEL_RADIUS = RIM_RADIUS - 6;
    var LABEL_MAX_WIDTH = LABEL_RADIUS - HUB_RADIUS;
    var CONFETTI_COLORS = ['#f5b301', '#066FEF', '#00095B', '#4D7DF2', '#FFFFFF'];
    var CONFETTI_DURATION_MS = 4200;
    var SPIN_DURATION_MS = 5200;
    var FULL_TURNS = 6;
    var MAX_LABEL_LENGTH = 22;

    var EXPAND_ICON_PATH = 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5';
    var COMPRESS_ICON_PATH = 'M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5';

    var dataNode = document.getElementById('roue-data');
    var wheelNode = document.getElementById('roue');
    var buttonNode = document.getElementById('roue-bouton');
    var errorNode = document.getElementById('roue-erreur');
    var resultNode = document.getElementById('roue-resultat');
    var confettiNode = document.getElementById('confetti-canvas');
    var confettiInstance = null;
    var confettiInterval = null;

    initFullscreenToggle();

    if (!dataNode || !wheelNode || !buttonNode) {
        return;
    }

    var config;
    try {
        config = JSON.parse(dataNode.textContent);
    } catch (error) {
        return;
    }

    var segments = Array.isArray(config.segments) ? config.segments : [];
    var prefersReducedMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var isSpinning = false;
    var isPlayed = Boolean(config.alreadyPlayed);
    var currentRotation = 0;

    render();

    if (segments.length === 0) {
        disableButton('Aucun lot n’est disponible pour le moment.');
        return;
    }

    if (isPlayed) {
        // Partie déjà jouée : la roue est figée sur le résultat obtenu.
        var playedIndex = config.playedPrizeUuid
            ? indexOfPrize(config.playedPrizeUuid)
            : randomLossIndex();
        if (playedIndex !== -1) {
            currentRotation = rotationForIndex(playedIndex, 0);
            wheelNode.style.transform = 'rotate(' + currentRotation + 'deg)';
        }
        buttonNode.disabled = true;
        return;
    }

    buttonNode.addEventListener('click', onSpin);

    /* ------------------------------------------------------------------ */

    function render() {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 200 200');
        svg.setAttribute('class', 'wheel__svg');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        // Attaché tout de suite : buildLabel() a besoin d'un SVG rendu pour
        // mesurer la largeur réelle des libellés (getBBox).
        wheelNode.appendChild(svg);

        if (segments.length === 0) {
            var empty = document.createElementNS(SVG_NS, 'circle');
            empty.setAttribute('cx', '100');
            empty.setAttribute('cy', '100');
            empty.setAttribute('r', '96');
            empty.setAttribute('fill', '#E7EDF7');
            svg.appendChild(empty);
            return;
        }

        var sweep = 360 / segments.length;

        segments.forEach(function (segment, index) {
            var color = segment.color || FALLBACK_COLORS[index % FALLBACK_COLORS.length];
            svg.appendChild(buildSlice(index, sweep, color));
            svg.appendChild(buildLabel(segment, index, sweep, color));
        });

        var rim = document.createElementNS(SVG_NS, 'circle');
        rim.setAttribute('cx', '100');
        rim.setAttribute('cy', '100');
        rim.setAttribute('r', '96');
        rim.setAttribute('fill', 'none');
        rim.setAttribute('stroke', '#FFFFFF');
        rim.setAttribute('stroke-width', '4');
        svg.appendChild(rim);

        fitLabels(svg);
    }

    function fitLabels(svg) {
        var labels = svg.querySelectorAll('.wheel__label');

        labels.forEach(function (label) {
            try {
                var width = label.getBBox().width;
                if (width > LABEL_MAX_WIDTH) {
                    label.setAttribute('textLength', String(LABEL_MAX_WIDTH));
                    label.setAttribute('lengthAdjust', 'spacingAndGlyphs');
                }
            } catch (error) {
                // getBBox indisponible (environnement de test) : on laisse le libellé tel quel.
            }
        });
    }

    function buildSlice(index, sweep, color) {
        var start = -90 + index * sweep;
        var end = start + sweep;
        var path = document.createElementNS(SVG_NS, 'path');

        path.setAttribute('d', segments.length === 1
            ? 'M 100 4 A 96 96 0 1 1 99.9 4 Z'
            : describeSlice(start, end));
        path.setAttribute('fill', color);
        path.setAttribute('stroke', '#FFFFFF');
        path.setAttribute('stroke-width', '1');

        return path;
    }

    function describeSlice(startDeg, endDeg) {
        var radius = 96;
        var from = pointOnCircle(startDeg, radius);
        var to = pointOnCircle(endDeg, radius);
        var largeArc = endDeg - startDeg > 180 ? 1 : 0;

        return 'M 100 100'
            + ' L ' + from.x + ' ' + from.y
            + ' A ' + radius + ' ' + radius + ' 0 ' + largeArc + ' 1 ' + to.x + ' ' + to.y
            + ' Z';
    }

    function pointOnCircle(angleDeg, radius) {
        var radians = angleDeg * Math.PI / 180;

        return {
            x: round(100 + radius * Math.cos(radians)),
            y: round(100 + radius * Math.sin(radians))
        };
    }

    function buildLabel(segment, index, sweep, color) {
        var middle = -90 + (index + 0.5) * sweep;
        var normalized = ((middle % 360) + 360) % 360;
        var flipped = normalized > 90 && normalized < 270;

        var text = document.createElementNS(SVG_NS, 'text');
        text.setAttribute('x', String(flipped ? 100 - LABEL_RADIUS : 100 + LABEL_RADIUS));
        text.setAttribute('y', '100');
        text.setAttribute('text-anchor', flipped ? 'start' : 'end');
        text.setAttribute('dominant-baseline', 'middle');
        text.setAttribute('transform', 'rotate(' + round(flipped ? middle + 180 : middle) + ' 100 100)');
        text.setAttribute('class', 'wheel__label');
        text.setAttribute('fill', isDarkColor(color) ? '#FFFFFF' : '#00095B');
        text.textContent = shorten(segment.name);

        var title = document.createElementNS(SVG_NS, 'title');
        title.textContent = segment.name;
        text.appendChild(title);

        return text;
    }

    function shorten(label) {
        var value = String(label || '');

        return value.length > MAX_LABEL_LENGTH
            ? value.slice(0, MAX_LABEL_LENGTH - 1).trimEnd() + '…'
            : value;
    }

    function isDarkColor(hex) {
        var value = String(hex || '').replace('#', '');

        if (value.length !== 6) {
            return true;
        }

        var r = parseInt(value.slice(0, 2), 16);
        var g = parseInt(value.slice(2, 4), 16);
        var b = parseInt(value.slice(4, 6), 16);

        // Luminance relative approchée (ITU-R BT.601).
        return (0.299 * r + 0.587 * g + 0.114 * b) < 150;
    }

    function round(value) {
        return Math.round(value * 1000) / 1000;
    }

    /* ------------------------------------------------------------------ */

    function onSpin() {
        // Garde-fou anti double-clic : le backend est de toute façon idempotent.
        if (isSpinning || isPlayed) {
            return;
        }

        isSpinning = true;
        buttonNode.disabled = true;
        buttonNode.textContent = 'La roue tourne…';
        hideError();

        fetch(config.spinUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': config.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.payload || result.payload.status !== 'success') {
                    throw new Error(messageOf(result.payload));
                }

                isPlayed = true;

                return spinTo(result.payload.data).then(function () {
                    showResult(result.payload.data);
                });
            })
            .catch(function (error) {
                isSpinning = false;
                buttonNode.disabled = false;
                buttonNode.textContent = 'Faire tourner la roue';
                showError(error && error.message
                    ? error.message
                    : 'Le tirage n’a pas pu aboutir. Merci de réessayer.');
            });
    }

    function messageOf(payload) {
        return payload && payload.message
            ? payload.message
            : 'Le tirage n’a pas pu aboutir. Merci de réessayer.';
    }

    function spinTo(result) {
        var index = result.prizeUuid ? indexOfPrize(result.prizeUuid) : randomLossIndex();

        if (index === -1) {
            // La dotation a changé depuis l'affichage de la page : on recharge
            // pour retrouver une roue cohérente avec le résultat enregistré.
            window.location.reload();

            return new Promise(function () {});
        }

        var jitter = (Math.random() - 0.5) * (360 / segments.length) * 0.6;
        var target = rotationForIndex(index, jitter);

        if (prefersReducedMotion) {
            wheelNode.style.transform = 'rotate(' + target + 'deg)';
            currentRotation = target;

            return delay(400);
        }

        return new Promise(function (resolve) {
            var settled = false;
            var finish = function () {
                if (settled) {
                    return;
                }
                settled = true;
                wheelNode.removeEventListener('transitionend', finish);
                wheelNode.dataset.spinning = 'false';
                resolve();
            };

            wheelNode.dataset.spinning = 'true';
            wheelNode.addEventListener('transitionend', finish);
            // Filet de sécurité si « transitionend » ne se déclenche pas.
            window.setTimeout(finish, SPIN_DURATION_MS + 600);

            // Force un reflow pour que la transition démarre à coup sûr.
            void wheelNode.offsetWidth;
            wheelNode.style.transform = 'rotate(' + target + 'deg)';
            currentRotation = target;
        });
    }

    /**
     * Rotation amenant le secteur demandé sous l'aiguille, placée en haut.
     */
    function rotationForIndex(index, jitter) {
        var sweep = 360 / segments.length;
        var segmentCentre = index * sweep + sweep / 2;
        var base = Math.ceil(currentRotation / 360) * 360;

        return round(base + FULL_TURNS * 360 - segmentCentre + jitter);
    }

    function indexOfPrize(uuid) {
        if (!uuid) {
            return -1;
        }

        for (var i = 0; i < segments.length; i++) {
            if (segments[i].uuid === uuid) {
                return i;
            }
        }

        return -1;
    }

    /**
     * Choisit une case « perdu » au hasard côté client : le backend ne
     * distingue pas laquelle, toutes ont la même signification.
     */
    function randomLossIndex() {
        var lossIndexes = [];

        for (var i = 0; i < segments.length; i++) {
            if (segments[i].type === 'loss') {
                lossIndexes.push(i);
            }
        }

        if (lossIndexes.length === 0) {
            return -1;
        }

        return lossIndexes[Math.floor(Math.random() * lossIndexes.length)];
    }

    function delay(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    /* ------------------------------------------------------------------ */

    function showResult(result) {
        if (!resultNode) {
            window.location.reload();

            return;
        }

        setText('roue-resultat-titre', result.title);
        setText('roue-resultat-badge', result.badge);

        var detail = document.getElementById('roue-resultat-detail');
        if (detail) {
            detail.textContent = result.detail || '';
            detail.hidden = !result.detail;
        }

        var thanks = document.getElementById('roue-resultat-merci');
        if (thanks) {
            thanks.hidden = false;
        }

        var card = document.getElementById('roue-resultat-carte');
        if (card) {
            card.classList.toggle('result__card--win', Boolean(result.isMainPrize));
        }

        buttonNode.textContent = 'Faire tourner la roue';
        resultNode.hidden = false;

        // La célébration confettis ne marque que le gain du lot principal.
        if (result.isMainPrize) {
            launchConfetti();
        }

        var focusable = resultNode.querySelector('button');
        if (focusable) {
            focusable.focus();
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Pluie de confettis tombant du haut de l'écran, via la librairie
     * canvas-confetti (vendée en local, cf. assets/js/vendor).
     */
    function launchConfetti() {
        if (!confettiNode || !window.confetti || prefersReducedMotion) {
            return;
        }

        if (!confettiInstance) {
            confettiInstance = window.confetti.create(confettiNode, { resize: true });
        }

        confettiNode.hidden = false;

        if (confettiInterval) {
            window.clearInterval(confettiInterval);
        }

        var elapsed = 0;
        var tick = 40;

        confettiInterval = window.setInterval(function () {
            elapsed += tick;

            confettiInstance({
                particleCount: 4,
                startVelocity: 0,
                ticks: 300,
                gravity: 0.7,
                spread: 360,
                scalar: 1.1,
                origin: { x: Math.random(), y: -0.1 },
                colors: CONFETTI_COLORS
            });

            if (elapsed >= CONFETTI_DURATION_MS) {
                window.clearInterval(confettiInterval);
                confettiInterval = null;
                window.setTimeout(function () {
                    confettiInstance.reset();
                    confettiNode.hidden = true;
                }, 2500);
            }
        }, tick);
    }

    function setText(id, value) {
        var node = document.getElementById(id);
        if (node) {
            node.textContent = value || '';
        }
    }

    function showError(message) {
        if (!errorNode) {
            return;
        }

        errorNode.textContent = message;
        errorNode.hidden = false;
    }

    function hideError() {
        if (errorNode) {
            errorNode.hidden = true;
            errorNode.textContent = '';
        }
    }

    function disableButton(message) {
        buttonNode.disabled = true;
        showError(message);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Passe la scène de jeu (titre, roue, légende) en plein écran. Masqué
     * par défaut dans le HTML : n'apparaît que si l'API est disponible, ce
     * qui exclut notamment Safari iOS (aucune prise en charge du plein
     * écran hors lecture vidéo).
     */
    function initFullscreenToggle() {
        var target = document.getElementById('roue-scene');
        var toggleButton = document.getElementById('roue-plein-ecran');
        var label = document.getElementById('roue-plein-ecran-libelle');
        var icon = document.getElementById('roue-plein-ecran-icone');

        if (!target || !toggleButton || !label) {
            return;
        }

        var requestFullscreen = target.requestFullscreen
            || target.webkitRequestFullscreen
            || target.mozRequestFullScreen
            || target.msRequestFullscreen;

        if (!requestFullscreen) {
            return;
        }

        toggleButton.hidden = false;
        toggleButton.addEventListener('click', onToggle);

        ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(
            function (eventName) {
                document.addEventListener(eventName, syncState);
            }
        );

        syncState();

        function onToggle() {
            if (isFullscreen()) {
                exitFullscreen();

                return;
            }

            var result = requestFullscreen.call(target);
            if (result && typeof result.catch === 'function') {
                // Refus du navigateur (ex. hors interaction utilisateur) :
                // rien de plus à faire, syncState() gardera l'état cohérent.
                result.catch(function () {});
            }
        }

        function exitFullscreen() {
            var exit = document.exitFullscreen
                || document.webkitExitFullscreen
                || document.mozCancelFullScreen
                || document.msExitFullscreen;

            if (exit) {
                exit.call(document);
            }
        }

        function isFullscreen() {
            var current = document.fullscreenElement
                || document.webkitFullscreenElement
                || document.mozFullScreenElement
                || document.msFullscreenElement;

            return current === target;
        }

        function syncState() {
            var active = isFullscreen();

            toggleButton.setAttribute('aria-pressed', active ? 'true' : 'false');
            label.textContent = active ? 'Quitter le plein écran' : 'Plein écran';

            if (icon) {
                icon.setAttribute('d', active ? COMPRESS_ICON_PATH : EXPAND_ICON_PATH);
            }
        }
    }
})();
