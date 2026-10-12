// Charts. An element with data-chart is drawn by ApexCharts, which is fetched only on a page
// that has one. The figures come from the page and are only formatted here.
//
//   Columns side by side, one group per category, in dollars (given in cents):
//   <div data-chart="sales-by-month" data-categories='["Jul 2026", …]' data-series='[{"name": "Invoiced", "data": [350000, …]}, …]'></div>
//
//   A line over time, in milliseconds (points of [moment in ms, value or null for a gap]):
//   <div data-chart="response-time" data-chart-type="line" data-unit="ms" data-series='[{"name": "Response time", "data": [[1760493600000, 184], …]}]'></div>
//
// Colours are the --series-N custom properties and Bootstrap's own text and border colours, read
// from the element when it is drawn and again when the light/dark switch is used.
const elements = document.querySelectorAll('[data-chart]');

const dollars = new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD' });
const compact = new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD', notation: 'compact', maximumFractionDigits: 1 });

// How a unit's values are written in full (a tooltip) and briefly (an axis). Money arrives in cents.
const units = {
    money: { full: (cents) => dollars.format(cents / 100), brief: (cents) => compact.format(cents / 100) },
    ms: { full: (ms) => (ms === null || ms === undefined ? 'No answer' : `${Math.round(ms)} ms`), brief: (ms) => `${Math.round(ms)} ms` },
};

const colours = (element) => {
    const style = getComputedStyle(element);
    const read = (name) => style.getPropertyValue(name).trim();

    return {
        series: [read('--series-1'), read('--series-2')],
        text: read('--bs-secondary-color'),
        grid: read('--bs-border-color'),
        dark: document.documentElement.getAttribute('data-bs-theme') === 'dark',
    };
};

// What changes with the theme.
const themed = (element) => {
    const { series, text, grid, dark } = colours(element);
    const unit = units[element.dataset.unit] ?? units.money;

    return {
        colors: series,
        theme: { mode: dark ? 'dark' : 'light' },
        grid: { borderColor: grid, strokeDashArray: 3 },
        xaxis: { labels: { style: { colors: text } }, axisBorder: { color: grid }, axisTicks: { show: false } },
        yaxis: { min: 0, labels: { style: { colors: text }, formatter: unit.brief } },
        legend: { labels: { colors: text } },
    };
};

const shared = (element, height) => ({
    chart: { height, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', background: 'transparent', animations: { speed: 300 } },
    series: JSON.parse(element.dataset.series),
    dataLabels: { enabled: false },
    tooltip: { shared: true, intersect: false, y: { formatter: (units[element.dataset.unit] ?? units.money).full } },
});

// Two columns a category, side by side on one axis.
const columns = (element) => {
    const theme = themed(element);
    const base = shared(element, 320);

    return {
        ...theme,
        ...base,
        chart: { ...base.chart, type: 'bar' },
        xaxis: { ...theme.xaxis, categories: JSON.parse(element.dataset.categories) },
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
        // A gap of the surface between the two columns of a category.
        stroke: { show: true, width: 2, colors: ['transparent'] },
        // The colours as they were chosen, not ApexCharts' lighter default.
        fill: { opacity: 1 },
        legend: { ...theme.legend, position: 'top', horizontalAlign: 'left', markers: { size: 6, shape: 'square' } },
        states: { hover: { filter: { type: 'lighten' } } },
    };
};

// One series over time. A null value leaves a gap in the line. The title names it, so no legend.
const line = (element) => {
    const theme = themed(element);
    const base = shared(element, 260);

    return {
        ...theme,
        ...base,
        chart: { ...base.chart, type: 'line' },
        xaxis: { ...theme.xaxis, type: 'datetime', labels: { ...theme.xaxis.labels, datetimeUTC: false }, crosshairs: { show: true } },
        stroke: { width: 2, curve: 'straight' },
        markers: { size: 0, hover: { size: 5 } },
        legend: { show: false },
        tooltip: { ...base.tooltip, x: { format: 'd MMM, h:mm tt' } },
    };
};

const options = (element) => (element.dataset.chartType === 'line' ? line(element) : columns(element));

if (elements.length) {
    import('apexcharts').then(({ default: ApexCharts }) => {
        const charts = [...elements].map((element) => {
            const chart = new ApexCharts(element, options(element));
            chart.render();

            return [element, chart];
        });

        // The light/dark switch sets data-bs-theme on <html>.
        new MutationObserver(() => charts.forEach(([element, chart]) => chart.updateOptions(themed(element))))
            .observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    });
}
