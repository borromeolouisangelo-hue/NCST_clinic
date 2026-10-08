function drawBarChart(canvasId, labels, data, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var width = canvas.width;
    var height = canvas.height;
    var padding = { top: 30, right: 20, bottom: 40, left: 50 };
    var chartW = width - padding.left - padding.right;
    var chartH = height - padding.top - padding.bottom;
    var maxVal = Math.max(...data, 1);
    var barWidth = chartW / labels.length * 0.6;
    var gap = chartW / labels.length * 0.4;

    // Grid lines
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    for (var i = 0; i <= 5; i++) {
        var y = padding.top + (chartH / 5) * i;
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();
    }

    // Bars
    data.forEach(function(val, idx) {
        var x = padding.left + (chartW / labels.length) * idx + gap / 2;
        var barH = (val / maxVal) * chartH;
        var y = padding.top + chartH - barH;
        ctx.fillStyle = options.barColor || '#1a56a8';
        ctx.fillRect(x, y, barWidth, barH);
        // Label
        ctx.fillStyle = '#6c757d';
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(labels[idx], x + barWidth / 2, height - 10);
        // Value
        ctx.fillStyle = '#212529';
        ctx.font = '11px sans-serif';
        ctx.fillText(val, x + barWidth / 2, y - 5);
    });
}

function drawHorizontalBarChart(canvasId, labels, data, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    data = Array.isArray(data) ? data : [];
    labels = Array.isArray(labels) ? labels : [];
    if (!data.length) {
        var emptyCtx = canvas.getContext('2d');
        var emptyPadding = { top: 12, right: 34, bottom: 12, left: 132 };
        var emptyChartW = canvas.width - emptyPadding.left - emptyPadding.right;
        var emptyRowHeight = (canvas.height - emptyPadding.top - emptyPadding.bottom) / 5;
        emptyCtx.clearRect(0, 0, canvas.width, canvas.height);
        emptyCtx.fillStyle = '#91a0b2';
        emptyCtx.font = '12px sans-serif';
        emptyCtx.textAlign = 'center';
        for (var emptyRow = 0; emptyRow < 5; emptyRow++) {
            var emptyY = emptyPadding.top + emptyRowHeight * emptyRow + emptyRowHeight / 2;
            emptyCtx.fillStyle = '#edf1f5';
            emptyCtx.fillRect(emptyPadding.left, emptyY - 4, emptyChartW, 8);
        }
        emptyCtx.fillStyle = '#91a0b2';
        emptyCtx.fillText('No data available', canvas.width / 2, canvas.height / 2 + 4);
        return;
    }

    var width = canvas.width;
    var height = canvas.height;
    var padding = { top: 8, right: 34, bottom: 8, left: 132 };
    var chartW = width - padding.left - padding.right;
    var chartH = height - padding.top - padding.bottom;
    var maxVal = Math.max.apply(null, data.concat([1]));
    var rowHeight = chartH / data.length;
    var barHeight = Math.max(8, rowHeight * .58);
    var color = (options && options.barColor) || '#7950f2';

    ctx.clearRect(0, 0, width, height);
    ctx.font = '10px sans-serif';
    data.forEach(function (value, index) {
        var centerY = padding.top + rowHeight * index + rowHeight / 2;
        var barWidth = (value / maxVal) * chartW;
        var label = String(labels[index] || 'Item');
        if (label.length > 19) label = label.slice(0, 18) + '...';

        ctx.fillStyle = '#65748a';
        ctx.textAlign = 'right';
        ctx.fillText(label, padding.left - 10, centerY + 3);
        ctx.fillStyle = '#edf1f7';
        ctx.fillRect(padding.left, centerY - barHeight / 2, chartW, barHeight);
        ctx.fillStyle = color;
        ctx.fillRect(padding.left, centerY - barHeight / 2, barWidth, barHeight);
        ctx.fillStyle = '#263442';
        ctx.textAlign = 'left';
        ctx.fillText(value, padding.left + barWidth + 6, centerY + 3);
    });
}

function drawPieChart(canvasId, labels, data, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    data = Array.isArray(data) ? data : [];
    labels = Array.isArray(labels) ? labels : [];
    var total = data.reduce(function (sum, value) { return sum + value; }, 0);
    if (!data.length || !total) {
        drawEmptyChart(canvas, 'No data available', true);
        return;
    }
    var colors = (options && options.colors) || ['#1a56a8', '#f5a623', '#2f9e44', '#d94841', '#7950f2', '#0ca678', '#e8590c', '#495057'];
    var centerX = canvas.width / 2;
    var centerY = canvas.height / 2;
    var radius = Math.min(canvas.width, canvas.height) * 0.32;
    var startAngle = -Math.PI / 2;

    data.forEach(function (value, index) {
        var slice = (value / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(centerX, centerY);
        ctx.arc(centerX, centerY, radius, startAngle, startAngle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[index % colors.length];
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();
        startAngle += slice;
    });

    var legendX = canvas.width * 0.68;
    var legendY = 24;
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'left';
    labels.forEach(function (label, index) {
        var y = legendY + index * 20;
        ctx.fillStyle = colors[index % colors.length];
        ctx.fillRect(legendX, y - 9, 11, 11);
        ctx.fillStyle = '#212529';
        var percent = Math.round((data[index] / total) * 100);
        ctx.fillText(label + ' (' + data[index] + ', ' + percent + '%)', legendX + 16, y);
    });
}

function drawLineChart(canvasId, labels, data, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    data = Array.isArray(data) ? data : [];
    labels = Array.isArray(labels) ? labels : [];
    var width = canvas.width;
    var height = canvas.height;
    var padding = { top: 24, right: 18, bottom: 34, left: 34 };
    var chartW = width - padding.left - padding.right;
    var chartH = height - padding.top - padding.bottom;
    var color = (options && options.lineColor) || '#2f7bbd';
    var secondaryData = options && Array.isArray(options.secondaryData) ? options.secondaryData : null;
    var secondaryColor = (options && options.secondaryColor) || '#4a9f86';
    var allValues = data.concat(secondaryData || []);
    var maxVal = Math.max.apply(null, allValues.concat([1]));

    ctx.clearRect(0, 0, width, height);
    ctx.strokeStyle = (options && options.gridColor) || '#edf1f5';
    ctx.fillStyle = (options && options.mutedTextColor) || '#91a0b2';
    ctx.font = '10px sans-serif';
    ctx.textAlign = 'right';
    for (var grid = 0; grid <= 4; grid++) {
        var gridY = padding.top + chartH - (chartH / 4) * grid;
        ctx.beginPath();
        ctx.moveTo(padding.left, gridY);
        ctx.lineTo(width - padding.right, gridY);
        ctx.stroke();
        ctx.fillText(Math.round((maxVal / 4) * grid), padding.left - 6, gridY + 3);
    }

    if (!data.length && !secondaryData) {
        ctx.fillStyle = '#8492a6';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No data available', width / 2, height / 2);
        return;
    }

    var points = data.map(function (value, index) {
        return {
            x: padding.left + (labels.length === 1 ? chartW / 2 : (chartW / (labels.length - 1)) * index),
            y: padding.top + chartH - (value / maxVal) * chartH,
        };
    });

    function drawSeries(seriesData, seriesColor, lineWidth, showDots) {
        if (!seriesData || !seriesData.length) return;
        var seriesPoints = seriesData.map(function (value, index) {
            return {
                x: padding.left + (labels.length === 1 ? chartW / 2 : (chartW / (labels.length - 1)) * index),
                y: padding.top + chartH - (value / maxVal) * chartH,
            };
        });
        ctx.strokeStyle = seriesColor;
        ctx.lineWidth = lineWidth;
        ctx.beginPath();
        seriesPoints.forEach(function (point, index) {
            if (index === 0) ctx.moveTo(point.x, point.y);
            else ctx.lineTo(point.x, point.y);
        });
        ctx.stroke();
        if (showDots) {
            ctx.fillStyle = '#ffffff';
            ctx.strokeStyle = seriesColor;
            ctx.lineWidth = 2;
            seriesPoints.forEach(function (point) {
                ctx.beginPath();
                ctx.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.stroke();
            });
        }
    }

    if (points.length) {
        drawSeries(data, color, 3, true);
        drawSeries(secondaryData, secondaryColor, 2.5, false);
    }

    ctx.textAlign = 'center';
    var labelStep = Math.max(1, Math.ceil(labels.length / 8));
    labels.forEach(function (label, index) {
        if (index % labelStep !== 0 && index !== labels.length - 1) return;
        var point = points[index];
        ctx.fillStyle = '#6c757d';
        ctx.fillText(label, point.x, height - 10);
    });
}

function drawEmptyChart(canvas, message, ring) {
    var ctx = canvas.getContext('2d');
    var centerX = canvas.width / 2;
    var centerY = canvas.height / 2;
    var radius = Math.min(canvas.width, canvas.height) * 0.28;

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (ring) {
        ctx.beginPath();
        ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
        ctx.strokeStyle = '#dfe7f2';
        ctx.lineWidth = 24;
        ctx.stroke();
    }
    ctx.fillStyle = '#8492a6';
    ctx.font = '12px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(message, centerX, centerY + (ring ? radius + 34 : 0));
}
