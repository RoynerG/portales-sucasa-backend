<?php

namespace App\Services\Portals;

use App\Models\Property;

class ProppitFeedGenerator
{
    public function generate(): string
    {
        $properties = Property::with(['city', 'neighborhood', 'propertyType', 'transactionType', 'consultant', 'images'])
            ->published()
            ->get();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('properties');

        foreach ($properties as $property) {
            $this->writeProperty($xml, $property);
        }

        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }

    public function writeToFile(): string
    {
        $xml = $this->generate();
        $path = config('portals.proppit.feed_path');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $xml);
        return $path;
    }

    protected function writeProperty(\XMLWriter $xml, Property $p): void
    {
        $xml->startElement('property');
        $xml->writeElement('reference_id', $p->code);
        $xml->startElement('contact');
        $xml->writeElement('name', $p->contact_name);
        $xml->writeElement('email', $p->contact_email);
        $xml->writeElement('phone', $p->contact_phone);
        $xml->endElement();
        $xml->writeElement('title', $p->title);
        $xml->writeElement('description', $p->description);
        $xml->startElement('prices');
        $xml->writeElement('price', (string) ($p->display_price ?? 0));
        $xml->writeElement('currency', $p->currency ?? 'COP');
        $xml->endElement();
        $xml->writeElement('propertyType', $p->propertyType?->name ?? '');
        $xml->writeElement('transactionType', $p->transactionType?->name ?? '');
        $xml->writeElement('city', $p->city?->name ?? '');
        $xml->writeElement('neighborhood', $p->neighborhood?->name ?? '');
        if ($p->lat && $p->lng) {
            $xml->startElement('coordinates');
            $xml->writeElement('latitude', (string) $p->lat);
            $xml->writeElement('longitude', (string) $p->lng);
            $xml->endElement();
        }
        $xml->writeElement('bedrooms', (string) ($p->bedrooms ?? 0));
        $xml->writeElement('bathrooms', (string) ($p->bathrooms ?? 0));
        $xml->writeElement('furnished', $p->furnished ? 'yes' : 'no');
        $xml->writeElement('year', (string) ($p->year_built ?? ''));
        $xml->writeElement('floorArea', (string) ($p->area_built ?? $p->area_total ?? 0));
        $xml->startElement('pictures');
        foreach ($p->images as $image) {
            $xml->writeElement('picture', $image->url);
        }
        $xml->endElement();
        $xml->endElement();
    }
}
