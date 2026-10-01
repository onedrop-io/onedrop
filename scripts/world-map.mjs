// Builds resources/js/components/charts/world-map-data.json, the country shapes the Growth map draws:
// Natural Earth's 1:110m countries (public domain), projected with the Natural Earth projection into a
// 1000-wide box, without Antarctica. Run it again only to change the shapes: node scripts/world-map.mjs

import { writeFileSync } from 'node:fs';
import { naturalEarth } from '../resources/js/components/charts/natural-earth.ts';

const SOURCE =
    'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_110m_admin_0_countries.geojson';

/** Places without their own ISO code, drawn as part of the country they're usually counted in. */
const PARENT = { 'N. Cyprus': 'CY', Somaliland: 'SO' };

const { features } = await (await fetch(SOURCE)).json();
const countries = new Map();

for (const { properties, geometry } of features) {
    const code = PARENT[properties.NAME] ?? properties.ISO_A2_EH;

    if (code === 'AQ' || !/^[A-Z]{2}$/.test(code)) {
        continue;
    }

    const polygons =
        geometry.type === 'Polygon'
            ? [geometry.coordinates]
            : geometry.coordinates;
    const d = polygons
        .flat()
        .map((ring) => {
            const points = [];

            for (const [lon, lat] of ring) {
                const [x, y] = naturalEarth(lon, lat).map((v) =>
                    Number(v.toFixed(1)),
                );
                const last = points.at(-1);

                if (!last || last[0] !== x || last[1] !== y) {
                    points.push([x, y]);
                }
            }

            return points.length < 3
                ? ''
                : 'M' + points.map((p) => p.join(',')).join('L') + 'Z';
        })
        .join('');

    const country = countries.get(code) ?? {
        code,
        name: properties.NAME,
        d: '',
    };
    country.d += d;
    countries.set(code, country);
}

const json = JSON.stringify({ countries: [...countries.values()] });
writeFileSync(
    new URL(
        '../resources/js/components/charts/world-map-data.json',
        import.meta.url,
    ),
    json + '\n',
);
console.log(
    `${countries.size} countries, ${Math.round(json.length / 1024)} KB`,
);
