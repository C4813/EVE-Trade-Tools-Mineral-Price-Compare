/* global jQuery, ETTMC, ETTMC_Extended */
/* ETT Mineral Compare — Frontend JS
   Prices come from ETT Price Helper's database (loaded on page render).
   Fees are embedded server-side in ETTMC.fees — no AJAX call needed.
   Trade simulation runs entirely client-side using buffered order book data.
*/
jQuery(function ($) {
    'use strict';

    // Bail immediately if neither ETTMC table exists on this page.
    // Prevents conflicts if this script is somehow loaded outside a shortcode page.
    if ( !document.getElementById('eve-mineral-compare-tables') &&
         !document.getElementById('ettmc-profile-wrapper') ) return;

    var minerals = ETTMC.minerals;   // { type_id: name }
    var hubs     = ETTMC.hubs;       // [ { key, name } ]

    var extendedTradesData = (window.ETTMC_Extended && ETTMC_Extended.extendedTradesData) || {};

    // Per-hub fees keyed by hub NAME, populated from server-side ETTMC.fees.
    // ETTMC.fees is keyed by hub_key — convert to hub name here.
    var brokerageFees = {};
    var salesTaxRates = {};
    hubs.forEach(function (h) {
        var f = ETTMC.fees && ETTMC.fees[h.key];
        brokerageFees[h.name] = f ? (f.broker_fee / 100) : 0.03;
        salesTaxRates[h.name] = f ? (f.sales_tax  / 100) : 0.08;
    });

    var ASSUMED_UNITS = 100000;
    var MAX_QTY_CAP   = 100000;

    // ── Formatting ────────────────────────────────────────────────────────
    function formatNumber(val) {
        if (val === null || val === undefined || val === 'N/A') return 'N/A';
        var n = Number(val);
        if (!isFinite(n)) return 'N/A';
        if (n % 1 !== 0) return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return n.toLocaleString();
    }

    function emcFormatShortISK(n) {
        if (!isFinite(n)) return '—';
        var abs = Math.abs(n), sign = n < 0 ? '-' : '';
        function c2(num, div) { return Math.ceil((num + Number.EPSILON) * 100 / div) / 100; }
        if (abs >= 1e12) return sign + c2(abs, 1e12).toFixed(2) + 't';
        if (abs >= 1e9)  return sign + c2(abs, 1e9 ).toFixed(2) + 'b';
        if (abs >= 1e6)  return sign + c2(abs, 1e6 ).toFixed(2) + 'm';
        if (abs >= 1e3)  return sign + c2(abs, 1e3 ).toFixed(2) + 'k';
        return sign + Math.ceil(abs * 100) / 100;
    }

    function debounce(fn, ms) {
        var t;
        return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); };
    }

    // ── Hub helpers ───────────────────────────────────────────────────────
    function getAllowedHubs() {
        var h = $('#emc-hub-filters-best .emc-hub-toggle:checked').map(function () { return $(this).val(); }).get();
        return h.length ? h : hubs.map(function (x) { return x.name; });
    }

    function pickHub(mineral, field, objective, allowed) {
        if (!mineral || !mineral.hubs) return null;
        var allow = {};
        allowed.forEach(function (n) { allow[n] = 1; });
        var bestHub = null, bestPrice = null;
        $.each(mineral.hubs, function (hubName, hv) {
            if (!allow[hubName]) return;
            var price = hv && hv[field];
            if (price == null || price === 'N/A' || !isFinite(Number(price))) return;
            price = Number(price);
            if (bestPrice === null || (objective === 'min' ? price < bestPrice : price > bestPrice)) {
                bestHub = hubName; bestPrice = price;
            }
        });
        return bestHub ? { hub: bestHub, price: bestPrice } : null;
    }

    // ── Trade simulation ──────────────────────────────────────────────────
    function simulateDepthAcrossOrders(mineral, buyType, sellType, qtyLimit, allowedHubs) {
        if (!mineral || !mineral.hubs) return null;

        var buyBest  = buyType  === 'buy' ? pickHub(mineral, 'buy',  'min', allowedHubs) : pickHub(mineral, 'sell', 'min', allowedHubs);
        var sellBest = sellType === 'buy' ? pickHub(mineral, 'buy',  'max', allowedHubs) : pickHub(mineral, 'sell', 'max', allowedHubs);
        if (!buyBest || !sellBest) return null;

        var buyHub  = buyBest.hub, sellHub = sellBest.hub;
        var buyUsesLadder  = (buyType  === 'sell');
        var sellUsesLadder = (sellType === 'buy');
        var anyLadder = buyUsesLadder || sellUsesLadder;

        var rawBuyOrders  = buyUsesLadder  ? ((mineral.hubs[buyHub]  && mineral.hubs[buyHub].sell_orders)  || []) : [];
        var rawSellOrders = sellUsesLadder ? ((mineral.hubs[sellHub] && mineral.hubs[sellHub].buy_orders)  || []) : [];

        var buyOrders = rawBuyOrders.map(function (o) { return { price: Number(o.price), vol: Number(o.vol) }; })
            .filter(function (o) { return isFinite(o.price) && o.price > 0 && isFinite(o.vol) && o.vol > 0; });
        var sellOrders = rawSellOrders.map(function (o) { return { price: Number(o.price), vol: Number(o.vol) }; })
            .filter(function (o) { return isFinite(o.price) && o.price > 0 && isFinite(o.vol) && o.vol > 0; });

        if (buyUsesLadder)  buyOrders.sort(function (a, b) { return a.price - b.price; });
        if (sellUsesLadder) sellOrders.sort(function (a, b) { return b.price - a.price; });

        function sumVol(arr) { return arr.reduce(function (s, o) { return s + o.vol; }, 0); }
        var ladderAvailBuy  = buyUsesLadder  ? sumVol(buyOrders)  : Infinity;
        var ladderAvailSell = sellUsesLadder ? sumVol(sellOrders) : Infinity;

        var userQtyLimit = (qtyLimit != null && isFinite(Number(qtyLimit)) && Number(qtyLimit) > 0) ? Number(qtyLimit) : null;
        var qtyCeiling;

        if (userQtyLimit != null) {
            if (anyLadder) {
                var lb = Math.min(buyUsesLadder ? ladderAvailBuy : Infinity, sellUsesLadder ? ladderAvailSell : Infinity);
                if (!isFinite(lb) || lb <= 0) return null;
                qtyCeiling = Math.min(userQtyLimit, lb);
            } else { qtyCeiling = Math.min(userQtyLimit, MAX_QTY_CAP); }
        } else if (anyLadder) {
            var lb2 = Math.min(buyUsesLadder ? ladderAvailBuy : Infinity, sellUsesLadder ? ladderAvailSell : Infinity);
            if (!isFinite(lb2) || lb2 <= 0) return null;
            qtyCeiling = lb2;
        } else { qtyCeiling = Math.min(ASSUMED_UNITS, MAX_QTY_CAP); }

        if (!buyUsesLadder)  buyOrders  = [{ price: Number(buyBest.price),  vol: qtyCeiling }];
        if (!sellUsesLadder) sellOrders = [{ price: Number(sellBest.price), vol: qtyCeiling }];

        var maxUnits = qtyCeiling;
        if (!isFinite(maxUnits) || maxUnits <= 0) maxUnits = ASSUMED_UNITS;
        if (!anyLadder && maxUnits > MAX_QTY_CAP) maxUnits = MAX_QTY_CAP;

        var buyFee    = Number(brokerageFees[buyHub]  || 0);
        var sellFee   = Number(brokerageFees[sellHub] || 0);
        var tax       = Number(salesTaxRates[sellHub] || 0);
        var minMargin = parseFloat($('#emc-min-margin').val()) || 5;

        var filled = 0, totalCost = 0, totalRevenue = 0, bi = 0, si = 0;

        while (filled < maxUnits && bi < buyOrders.length && si < sellOrders.length) {
            var stepQty = Math.min(buyOrders[bi].vol, sellOrders[si].vol, maxUnits - filled);
            if (!isFinite(stepQty) || stepQty <= 0) break;

            var stepCost = buyOrders[bi].price * stepQty;
            var stepRev  = sellOrders[si].price * stepQty;
            if (!isFinite(stepCost) || !isFinite(stepRev)) break;

            if (buyType  === 'buy')  stepCost *= (1 + buyFee);
            if (sellType === 'sell') stepRev  *= (1 - sellFee - tax);
            else                     stepRev  *= (1 - tax);

            var newCost = totalCost + stepCost, newRev = totalRevenue + stepRev;
            if (!isFinite(newCost) || !isFinite(newRev) || newCost > 1e15 || newRev > 1e15) break;

            var stepMargin = newCost > 0 ? ((newRev - newCost) / newCost) * 100 : 0;
            if (stepMargin < minMargin) break;

            totalCost = newCost; totalRevenue = newRev; filled += stepQty;
            buyOrders[bi].vol  -= stepQty; if (buyOrders[bi].vol  <= 0) bi++;
            sellOrders[si].vol -= stepQty; if (sellOrders[si].vol <= 0) si++;
        }

        var profit = totalRevenue - totalCost;
        var margin = totalCost > 0 ? (profit / totalCost) * 100 : 0;
        if (!isFinite(profit) || !isFinite(margin) || filled <= 0 || profit <= 0) return null;
        return { buyHub: buyHub, sellHub: sellHub, filledQty: filled, profit: profit, margin: margin, investment: totalCost };
    }

    // ── Margin colour scale ───────────────────────────────────────────────
    function colorForMargin(margin, minAll, maxAll) {
        if (!isFinite(margin)) return '#555';
        if (maxAll === minAll) return margin < 0 ? '#DB4325' : margin > 0 ? '#006164' : '#B9DCCF';
        if (margin < 0) return '#DB4325';
        if (maxAll <= 0) return margin === 0 ? '#B9DCCF' : '#DB4325';
        var span = Math.max(1e-9, Math.abs(maxAll));
        var t    = Math.pow(Math.min(Math.log1p(margin) / Math.log1p(span), 1), 0.6);
        var stops = ['#B9DCCF', '#57C4AD', '#006164'], pos = [0, 0.05, 1];
        var i = 0;
        while (i < pos.length - 2 && t > pos[i + 1]) i++;
        var lt = (t - pos[i]) / Math.max(1e-9, pos[i + 1] - pos[i]);
        function hexToRgb(h) { h=(h+'').replace('#',''); if(h.length===3)h=h.split('').map(function(c){return c+c;}).join(''); var n=parseInt(h,16); return{r:(n>>16)&255,g:(n>>8)&255,b:n&255}; }
        function pad2(v) { v=v.toString(16); return v.length===1?'0'+v:v; }
        function lerpHex(h1,h2,t2) { var a=hexToRgb(h1),b=hexToRgb(h2); return '#'+pad2(Math.round(a.r+(b.r-a.r)*t2))+pad2(Math.round(a.g+(b.g-a.g)*t2))+pad2(Math.round(a.b+(b.b-a.b)*t2)); }
        return lerpHex(stops[i], stops[i + 1], lt);
    }

    // ── Extended trades table ─────────────────────────────────────────────
    function updateExtendedTradeTable() {
        var buyType  = $('#buy-from-select-ext').val()  || 'buy';
        var sellType = $('#sell-to-select-ext').val()   || 'sell';
        var allowed  = getAllowedHubs();
        var qtyLimit = $('#emc-limit-60k').is(':checked') ? 6000000 : null;

        $('#emc-limit-60k-container .emc-limit-note').toggle(buyType === 'buy' && sellType === 'sell');

        var $tbody  = $('#eve-mc-extended tbody').empty();
        var margins = [], rows = 0;

        $.each(extendedTradesData, function (id, m) {
            if (!m || !m.hubs) return;
            var sim = simulateDepthAcrossOrders(m, buyType, sellType, qtyLimit, allowed);
            if (!sim) return;
            margins.push(sim.margin); rows++;
            $tbody.append(
                '<tr><td>' + (m.name || id) + '</td>' +
                '<td>' + sim.buyHub + '</td>' +
                '<td>' + sim.sellHub + '</td>' +
                '<td class="emc-td-right emc-td-nowrap">' + formatNumber(sim.filledQty) + '</td>' +
                '<td class="emc-td-right emc-td-nowrap">' + formatNumber(sim.profit) +
                    '<div class="emc-subtext"><em>Invest ' + emcFormatShortISK(sim.investment) + '</em></div></td>' +
                '<td class="emc-td-right emc-td-nowrap emc-margin" data-margin="' + sim.margin + '">' + sim.margin.toFixed(2) + '%</td>' +
                '</tr>'
            );
        });

        if (!rows) {
            $tbody.append('<tr><td colspan="6" style="text-align:center;color:#a00;font-weight:bold">No opportunities meet the current filters.</td></tr>');
        }

        var minAll = margins.length ? Math.min.apply(null, margins) : 0;
        var maxAll = margins.length ? Math.max.apply(null, margins) : 0;
        $('#eve-mc-extended td.emc-margin').each(function () {
            $(this).css('color', colorForMargin(parseFloat(this.getAttribute('data-margin')), minAll, maxAll));
        });
    }

    // ── No-Undock table ───────────────────────────────────────────────────
    function updateNoUndockTable() {
        var hubOrder = [];
        $('#emc-no-undock thead tr:first th').each(function (i) { if (i > 0) hubOrder.push($(this).text().trim()); });
        if (!hubOrder.length) return;

        var allMargins = [], byMineral = {};
        $.each(extendedTradesData, function (tid, m) {
            if (!m || !m.hubs) return;
            var row = {};
            hubOrder.forEach(function (hub) {
                var h = m.hubs[hub];
                var buy = h && h.buy, sell = h && h.sell;
                var margin = NaN;
                if (isFinite(Number(buy)) && isFinite(Number(sell))) {
                    var bf = Number(brokerageFees[hub] || 0), tx = Number(salesTaxRates[hub] || 0);
                    var cost = Number(buy)  * (1 + bf);
                    var rev  = Number(sell) * (1 - bf - tx);
                    if (cost > 0 && isFinite(rev)) {
                        margin = ((rev - cost) / cost) * 100;
                        if (isFinite(margin) && Math.abs(margin) <= 1000) allMargins.push(margin);
                        else margin = NaN;
                    }
                }
                row[hub] = margin;
            });
            byMineral[m.name || tid] = row;
        });

        var sorted = allMargins.slice().sort(function (a, b) { return a - b; });
        function pct(p) { if (!sorted.length) return 0; var i=(sorted.length-1)*p, lo=Math.floor(i), hi=Math.ceil(i); return lo===hi?sorted[lo]:sorted[lo]*(1-(i-lo))+sorted[hi]*(i-lo); }
        var span = Math.max(Math.abs(pct(0.05)), Math.abs(pct(0.95)), 1);

        var $tbody = $('#emc-no-undock tbody').empty();
        $.each(extendedTradesData, function (tid, m) {
            var name = m && (m.name || tid);
            var $tr  = $('<tr/>').append($('<td/>').text(name || ''));
            hubOrder.forEach(function (hub) {
                var mg = byMineral[name] ? byMineral[name][hub] : NaN;
                $('<td/>').addClass('emc-td-center emc-td-nowrap')
                    .text(isFinite(mg) ? mg.toFixed(2) + '%' : 'N/A')
                    .css('color', isFinite(mg) ? colorForMargin(mg, -span, span) : '#555')
                    .appendTo($tr);
            });
            $tbody.append($tr);
        });
    }

    // ── Reactive controls ─────────────────────────────────────────────────
    $(document).on('change', '#buy-from-select-ext, #sell-to-select-ext', updateExtendedTradeTable);
    $(document).on('change', '#emc-limit-60k',  updateExtendedTradeTable);
    $(document).on('change', '.emc-hub-toggle', updateExtendedTradeTable);
    $(document).on('input',  '#emc-min-margin', debounce(updateExtendedTradeTable, 200));

    // ── Init — fees are synchronous, render immediately ───────────────────
    $(function () {
        updateExtendedTradeTable();
        updateNoUndockTable();
    });

    // ── Character card accordion ──────────────────────────────────────────
    // Scoped to ETTMC profile wrappers to avoid conflicts with other plugins.
    if ( !window._ettmcAccordion ) {
        window._ettmcAccordion = true;
        document.addEventListener('click', function (e) {
            var header = e.target.closest('.ett-character-header');
            if (!header) return;
            if (!header.closest('.ettmc-profile-wrapper')) return;
            var body = header.nextElementSibling;
            if (body && body.classList.contains('ett-character-body')) {
                body.classList.toggle('open');
            }
        });
    }

}(jQuery));
