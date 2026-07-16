<?php

namespace App\Services\Portals;

use App\Models\Property;

class GoogleSitemapGenerator
{
    public function generate(): string
    {
        $properties = Property::with('neighborhood')
            ->published()
            ->get();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $base = rtrim(config('app.url'), '/');
        foreach ($properties as $property) {
            $xml->startElement('url');
            $xml->writeElement('loc', "{$base}/inmuebles/inmueble-{$property->code}");
            $xml->writeElement('lastmod', $property->updated_at?->toAtomString());
            $xml->writeElement('changefreq', 'weekly');
            $xml->writeElement('priority', '0.8');
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }

    public function writeToFile(): string
    {
        $xml = $this->generate();
        $path = config('portals.google.sitemap_path');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $xml);
        return $path;
    }
}
