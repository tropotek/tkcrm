// Charts. An element with data-chart is drawn by ApexCharts, which is fetched only on a page
// that has one. The figures come from the page, in cents, and are only formatted here:
//
//   <div data-chart="sales-by-month" data-categories='["Jul 2026", …]' data-series='[{"name": "Invoiced", "data": [350000, …]}, …]'></div>
//
// Colours are the --series-N custom properties and Bootstrap's own text and border colours, read
// from the element when it is drawn and again when the light/dark switch is used.
const elements = document.querySelectorAll('[data-chart]');

const dollars = new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD' });
const compact = new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD', notation: 'compact', maximumFractionDigits: 1 });

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

    return {
        colors: series,
        theme: { mode: dark ? 'dark' : 'light' },
        grid: { borderColor: grid, strokeDashArray: 3 },
        xaxis: { labels: { style: { colors: text } }, axisBorder: { color: grid }, axisTicks: { show: false } },
        yaxis: { labels: { style: { colors: text }, formatter: (cents) => compact.format(cents / 100) } },
        legend: { labels: { colors: text } },
    };
};

// Two columns a month, side by side on one axis: both series are dollars.
const columns = (element) => {
    const theme = themed(element);

    return {
        ...theme,
        chart: { type: 'bar', height: 320, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent', animations: { speed: 300 } },
        series: JSON.parse(element.dataset.series),
        xaxis: { ...theme.xaxis, categories: JSON.parse(element.dataset.categories) },
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
        // A gap of the surface between the two columns of a month.
        stroke: { show: true, width: 2, colors: ['transparent'] },
        // The colours as they were chosen, not ApexCharts' lighter default.
        fill: { opacity: 1 },
        dataLabels: { enabled: false },
        legend: { ...theme.legend, position: 'top', horizontalAlign: 'left', markers: { size: 6, shape: 'square' } },
        tooltip: { shared: true, intersect: false, y: { formatter: (cents) => dollars.format(cents / 100) } },
        states: { hover: { filter: { type: 'lighten' } } },
    };
};

if (elements.length) {
    import('apexcharts').then(({ default: ApexCharts }) => {
        const charts = [...elements].map((element) => {
            const chart = new ApexCharts(element, columns(element));
            chart.render();

            return [element, chart];
        });

        // The light/dark switch sets data-bs-theme on <html>.
        new MutationObserver(() => charts.forEach(([element, chart]) => chart.updateOptions(themed(element))))
            .observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    });
}
