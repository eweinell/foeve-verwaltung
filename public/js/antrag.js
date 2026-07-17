/* antrag.js — Live-Validierung des öffentlichen Antragsformulars (F2).
 *
 * Übernommen aus der Anmelde-App (index.php + js/utils.js). Zwei Abweichungen:
 *   - IBAN: dort nur für DE geprüft, hier MOD-97 für alle SEPA-Länder mit
 *     Längentabelle — sonst würden die jetzt wählbaren BE-/NL-Konten
 *     clientseitig als „gültig" durchgewinkt und erst der Server meckert.
 *   - PLZ: länderabhängig, spiegelt App\Service\Validierung::plzGueltig().
 *
 * Diese Prüfung ist reiner Komfort. Verbindlich validiert der Server.
 */
(function () {
    'use strict';

    /* Gesamtlänge der IBAN je Land — Spiegel von App\Service\Laender::IBAN_LAENGE. */
    var IBAN_LAENGE = {
        AD: 24, AT: 20, BE: 16, BG: 22, CH: 21, CY: 28, CZ: 24, DE: 22, DK: 18,
        EE: 20, ES: 24, FI: 18, FR: 27, GB: 22, GR: 27, HR: 21, HU: 28, IE: 22,
        IS: 26, IT: 27, LI: 21, LT: 20, LU: 20, LV: 21, MC: 27, MT: 31, NL: 18,
        NO: 15, PL: 28, PT: 25, RO: 24, SE: 24, SI: 19, SK: 24, SM: 27
    };

    function mod97(iban) {
        var umgestellt = iban.slice(4) + iban.slice(0, 4);
        var rest = 0;
        for (var i = 0; i < umgestellt.length; i++) {
            var z = umgestellt[i];
            var stueck = /[0-9]/.test(z) ? z : String(z.charCodeAt(0) - 55);
            for (var j = 0; j < stueck.length; j++) {
                rest = (rest * 10 + Number(stueck[j])) % 97;
            }
        }
        return rest;
    }

    function validiereIban(roh) {
        var iban = (roh || '').replace(/\s+/g, '').toUpperCase();

        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) {
            return { gueltig: false, fehler: 'Bitte geben Sie eine gültige IBAN ein (z. B. DE89, NL91, BE71).' };
        }
        var erwartet = IBAN_LAENGE[iban.slice(0, 2)];
        if (erwartet && iban.length !== erwartet) {
            return {
                gueltig: false,
                fehler: 'Eine IBAN aus ' + iban.slice(0, 2) + ' muss genau ' + erwartet + ' Zeichen lang sein.'
            };
        }
        if (iban.length < 15 || iban.length > 34) {
            return { gueltig: false, fehler: 'Bitte geben Sie eine gültige IBAN ein.' };
        }
        if (mod97(iban) !== 1) {
            return { gueltig: false, fehler: 'Die IBAN-Prüfsumme ist ungültig. Bitte prüfen Sie Ihre Eingabe.' };
        }
        return { gueltig: true, fehler: null };
    }

    function validierePlz(plz, land) {
        var wert = (plz || '').trim();
        if (land === 'DE') return /^\d{5}$/.test(wert);
        if (land === 'BE') return /^\d{4}$/.test(wert);
        if (land === 'NL') return /^\d{4}\s?[A-Za-z]{2}$/.test(wert);
        return wert !== '';
    }

    var $ = function (id) { return document.getElementById(id); };
    var form = $('antragsformular');
    if (!form) return;

    var meldungen = {
        anrede: 'Bitte wählen Sie eine Anrede.',
        nachname: 'Bitte geben Sie den Nachnamen ein (min. 2 Zeichen).',
        strasse: 'Bitte geben Sie Ihre Adresse ein (min. 3 Zeichen).',
        plz: 'Die Postleitzahl passt nicht zum gewählten Land.',
        ort: 'Bitte geben Sie den Ort ein (min. 2 Zeichen).',
        email: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        iban: 'Bitte geben Sie eine gültige IBAN ein (z. B. DE89, NL91, BE71).',
        kontoinhaber: 'Bitte geben Sie den Kontoinhaber an, da kein Vorname angegeben wurde.'
    };

    var pruefer = {
        anrede: function (v) { return ['herr', 'frau', 'familie'].indexOf(v) !== -1; },
        nachname: function (v) { return v.trim().length >= 2; },
        strasse: function (v) { return v.trim().length >= 3; },
        ort: function (v) { return v.trim().length >= 2; },
        email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); },
        plz: function (v) { return validierePlz(v, $('land').value); },
        // Ohne Vorname (z. B. „Familie Müller") braucht das Mandat einen Kontoinhaber.
        kontoinhaber: function (v) { return $('vorname').value.trim() !== '' || v.trim().length >= 2; },
        iban: function (v) {
            var e = validiereIban(v);
            if (!e.gueltig && e.fehler) meldungen.iban = e.fehler;
            return e.gueltig;
        }
    };

    function pruefeFeld(id) {
        var feld = $(id);
        var box = $(id + '-error');
        if (!feld || !pruefer[id]) return true;

        var ok = pruefer[id](feld.value);
        feld.classList.remove('error', 'valid');
        if (box) box.classList.remove('show');

        if (feld.value.trim() !== '' || id === 'anrede') {
            if (ok) {
                feld.classList.add('valid');
            } else {
                feld.classList.add('error');
                if (box) {
                    box.textContent = meldungen[id];
                    box.classList.add('show');
                }
            }
        }
        return ok;
    }

    ['anrede', 'nachname', 'strasse', 'plz', 'ort', 'email', 'iban', 'kontoinhaber'].forEach(function (id) {
        var feld = $(id);
        if (!feld) return;
        if (feld.tagName === 'SELECT') {
            feld.addEventListener('change', function () { pruefeFeld(id); });
        } else {
            feld.addEventListener('blur', function () { pruefeFeld(id); });
            feld.addEventListener('input', function () {
                if (feld.classList.contains('error')) pruefeFeld(id);
            });
        }
    });

    // Landwechsel ändert die PLZ-Regel; Vorname entscheidet über Kontoinhaber-Pflicht.
    $('land').addEventListener('change', function () {
        if ($('plz').value.trim() !== '') pruefeFeld('plz');
    });
    $('vorname').addEventListener('input', function () { pruefeFeld('kontoinhaber'); });

    // IBAN in Vierergruppen darstellen.
    $('iban').addEventListener('input', function (e) {
        var wert = e.target.value.replace(/\s/g, '').toUpperCase();
        var formatiert = wert.match(/.{1,4}/g);
        formatiert = formatiert ? formatiert.join(' ') : wert;
        if (e.target.value !== formatiert) e.target.value = formatiert;
    });

    // Wunschbetrag ein-/ausblenden.
    var wunschRadio = $('beitrag_wunsch_radio');
    var wunschBox = $('wunsch_container');
    var wunschFeld = $('jahresbeitrag_wunsch');

    function wunschUmschalten() {
        var an = wunschRadio && wunschRadio.checked;
        wunschBox.hidden = !an;
        wunschFeld.required = !!an;
    }
    Array.prototype.forEach.call(
        form.querySelectorAll('input[name="jahresbeitrag"]'),
        function (r) { r.addEventListener('change', wunschUmschalten); }
    );
    wunschUmschalten();

    [['datenschutz', 'datenschutz-error'], ['mandat', 'mandat-error']].forEach(function (paar) {
        var box = $(paar[0]);
        if (!box) return;
        box.addEventListener('change', function () {
            var fehler = $(paar[1]);
            if (fehler && box.checked) fehler.classList.remove('show');
        });
    });

    form.addEventListener('submit', function (e) {
        var ok = true;

        ['anrede', 'nachname', 'strasse', 'plz', 'ort', 'email', 'iban', 'kontoinhaber'].forEach(function (id) {
            if (!pruefeFeld(id)) ok = false;
        });

        var gewaehlt = form.querySelector('input[name="jahresbeitrag"]:checked');
        var betragFehler = $('beitrag-error');
        if (!gewaehlt) {
            ok = false;
        } else if (gewaehlt.value === 'wunsch') {
            var betrag = parseFloat(String(wunschFeld.value).replace(',', '.'));
            var min = parseFloat(wunschFeld.min);
            var max = parseFloat(wunschFeld.max);
            if (isNaN(betrag) || betrag < min || betrag > max) {
                betragFehler.classList.add('show');
                wunschFeld.classList.add('error');
                ok = false;
            } else {
                betragFehler.classList.remove('show');
                wunschFeld.classList.remove('error');
            }
        }

        [['datenschutz', 'datenschutz-error'], ['mandat', 'mandat-error']].forEach(function (paar) {
            var box = $(paar[0]);
            var fehler = $(paar[1]);
            if (box && !box.checked) {
                if (fehler) fehler.classList.add('show');
                ok = false;
            }
        });

        if (!ok) {
            e.preventDefault();
            var erster = form.querySelector('.error, .error-message.show');
            if (erster) erster.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        var btn = $('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Wird verarbeitet …';
    });

    // Schwebender CTA-Knopf, solange der statische außer Sicht ist.
    var floating = $('floatingCTA');
    var statisch = $('topCTA');
    if (floating && statisch) {
        var aktualisiere = function () {
            var r = statisch.getBoundingClientRect();
            if (r.bottom > 0 && r.top < window.innerHeight) {
                floating.classList.remove('visible');
            } else if (r.top > window.innerHeight) {
                floating.classList.add('visible');
            }
        };
        window.addEventListener('scroll', aktualisiere, { passive: true });
        window.addEventListener('resize', aktualisiere);
        aktualisiere();
    }

    Array.prototype.forEach.call(document.querySelectorAll('.smooth-scroll'), function (link) {
        link.addEventListener('click', function (e) {
            var ziel = document.querySelector(link.getAttribute('href'));
            if (!ziel) return;
            e.preventDefault();
            window.scrollTo({ top: ziel.getBoundingClientRect().top + window.pageYOffset - 20, behavior: 'smooth' });
        });
    });

    // Absenden erst nach gelöstem Captcha (Widget meldet sich per Event).
    var captcha = $('trustcaptchaComponent');
    if (captcha) {
        captcha.addEventListener('captchaSolved', function () {
            var btn = $('submitBtn');
            btn.disabled = false;
            btn.removeAttribute('aria-disabled');
            var hinweis = $('captcha-hint');
            if (hinweis) hinweis.remove();
        });
        captcha.addEventListener('captchaFailed', function (ev) {
            console.error(ev.detail);
        });
    }
})();
