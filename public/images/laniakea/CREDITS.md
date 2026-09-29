# Laniakea

`galaxies.bin` holds the positions of 41,577 galaxies from the 2MASS Redshift Survey: Huchra, J. P., et al. 2012, ApJS, 199, 26, [catalog J/ApJS/199/26](https://doi.org/10.26093/cds/vizier.21990026), retrieved through the VizieR catalogue access tool, CDS, Strasbourg, France.

Each galaxy's right ascension, declination, and redshift were turned into supergalactic coordinates, with its distance from its redshift (H₀ = 70 km/s/Mpc), keeping those within 260 Mpc. The file is a little-endian `uint32` count, then `int16` x, y, z per galaxy in hundredths of a megaparsec, then one brightness byte per galaxy from its K-band magnitude.

The flows and Laniakea's outline in the home page are an artist's approximation of Tully et al. (2014), not measured data. The home page credits the survey in its footer.
