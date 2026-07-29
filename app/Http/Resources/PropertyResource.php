<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'condition' => $this->condition,

            'city' => $this->city?->name,
            'city_id' => $this->city_id,
            'neighborhood' => $this->neighborhood?->name,
            'neighborhood_id' => $this->neighborhood_id,
            'address' => $this->address,
            'address_extra' => $this->address_extra,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'show_exact_address' => $this->show_exact_address,

            'property_type' => $this->propertyType?->name,
            'property_type_id' => $this->property_type_id,
            'transaction_type' => $this->transactionType?->name,
            'transaction_type_id' => $this->transaction_type_id,

            'sale_price' => $this->sale_price,
            'rent_price' => $this->rent_price,
            'admin_price' => $this->admin_price,
            'currency' => $this->currency,
            'display_price' => $this->display_price,
            'price_negotiable' => $this->price_negotiable,

            'area_total' => $this->area_total,
            'area_built' => $this->area_built,
            'area_private' => $this->area_private,
            'area_land' => $this->area_land,
            'display_area' => $this->display_area,

            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'half_bathrooms' => $this->half_bathrooms,
            'parking_spaces' => $this->parking_spaces,
            'parking_type' => $this->parking_type,
            'floor_number' => $this->floor_number,
            'age_years' => $this->age_years,
            'year_built' => $this->year_built,
            'stratum' => $this->stratum,
            'furnished' => $this->furnished,

            'project_name' => $this->project_name,
            'in_closed_complex' => $this->in_closed_complex,

            'status' => $this->status,
            'featured' => $this->featured,
            'exclusive' => $this->exclusive,
            'published_at' => $this->published_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),

            'consultant' => $this->consultant?->name,
            'consultant_id' => $this->consultant_id,

            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_whatsapp' => $this->contact_whatsapp,
            'contact_email' => $this->contact_email,

            'views_count' => $this->views_count,
            'leads_count' => $this->leads_count,

            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => $img->url,
                'thumbnail' => $img->thumbnail_url,
                'is_cover' => $img->is_cover,
                'order' => $img->order,
            ])
            ),
            'videos' => $this->whenLoaded('videos'),
            'floor_plans' => $this->whenLoaded('floorPlans'),

            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'group' => $f->group,
                'icon' => $f->icon,
                'value' => $f->pivot->value,
            ])
            ),

            'sync_statuses' => $this->whenLoaded('syncStatuses', fn () => $this->syncStatuses->map(fn ($s) => [
                'portal' => $s->integration?->slug,
                'portal_name' => $s->integration?->name,
                'environment' => $s->environment,
                'portal_variant' => $s->portal_variant,
                'sync_status' => $s->sync_status,
                'external_id' => $s->external_id,
                'external_url' => $s->external_url,
                'last_response' => $s->last_response,
                'last_error' => $s->last_error,
                'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            ])
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
