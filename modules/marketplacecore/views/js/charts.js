(function () {
  window.mpMountChart = function (id, labels, values) {
    var host = document.getElementById(id);
    if (!host) {
      return;
    }
    labels = labels || [];
    values = values || [];
    var data = labels.map(function (label, index) {
      return { label: label, value: Number(values[index] || 0) };
    });
    if (!data.length || !window.React || !window.Recharts || !window.ReactDOM) {
      host.textContent = 'No recorded orders yet';
      return;
    }
    var R = window.React;
    var C = window.Recharts;
    var bar = data.length < 3;
    var series = bar
      ? R.createElement(C.Bar, { dataKey: 'value', fill: '#312e81', radius: [8, 8, 0, 0], maxBarSize: 46 })
      : R.createElement(C.Area, { type: 'monotone', dataKey: 'value', stroke: '#312e81', strokeWidth: 2, fill: '#312e81', fillOpacity: 0.14 });
    window.ReactDOM.createRoot(host).render(
      R.createElement(C.ResponsiveContainer, { width: '100%', height: 280 },
        R.createElement(bar ? C.BarChart : C.AreaChart, { data: data, margin: { top: 16, right: 8, left: -12, bottom: 0 } },
          R.createElement(C.CartesianGrid, { stroke: '#e7e3dc', vertical: false }),
          R.createElement(C.XAxis, { dataKey: 'label', tick: { fill: '#78716c', fontSize: 12 }, axisLine: false, tickLine: false }),
          R.createElement(C.YAxis, { tick: { fill: '#78716c', fontSize: 12 }, axisLine: false, tickLine: false, width: 42, allowDecimals: false }),
          R.createElement(C.Tooltip, { cursor: { fill: 'rgba(49,46,129,.06)' }, contentStyle: { borderRadius: 12, border: '1px solid #e7e3dc', boxShadow: 'none' } }),
          series
        )
      )
    );
  };
})();
