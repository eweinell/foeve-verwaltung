/* antrag-warten.js — Countdown der Warteseite (F2), aus der Anmelde-App portiert.
 *
 * Die Restzeit kommt vom Server (data-wartet); hier läuft sie nur herunter und
 * gibt danach den Knopf frei. Die Sperre selbst prüft der Server erneut — die
 * Anzeige ist reine Bequemlichkeit.
 */
(function () {
    'use strict';

    var box = document.getElementById('timerBox');
    if (!box) return;

    var rest = parseInt(box.dataset.wartet, 10);
    if (isNaN(rest) || rest <= 0) return;

    var countdown = document.getElementById('countdown');
    var knopf = document.getElementById('resendBtn');
    var text = document.getElementById('timerText');
    var ende = Math.floor(Date.now() / 1000) + rest;

    function tick() {
        var verbleibend = ende - Math.floor(Date.now() / 1000);

        if (verbleibend <= 0) {
            countdown.textContent = '';
            knopf.disabled = false;
            box.classList.add('ready');
            text.innerHTML = '<strong>E-Mail nicht erhalten?</strong><br>' +
                'Sie können jetzt eine neue Bestätigungs-E-Mail anfordern.';
            return;
        }

        var min = Math.floor(verbleibend / 60);
        var sek = verbleibend % 60;
        countdown.textContent = 'Erneut senden möglich in: ' + min + ':' + String(sek).padStart(2, '0') + ' Minuten';
        setTimeout(tick, 1000);
    }

    tick();
})();
