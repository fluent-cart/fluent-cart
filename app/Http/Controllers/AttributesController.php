<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\Resource\AttrGroupResource;
use FluentCart\Api\Resource\AttrTermResource;
use FluentCart\App\Http\Requests\AttrGroupRequest;
use FluentCart\App\Http\Requests\AttrTermRequest;
use FluentCart\App\Http\Requests\AttrTermUpdateRequest;
use FluentCart\App\Services\Filter\AttrGroupFilter;
use FluentCart\App\Services\Filter\AttrTermFilter;

class AttributesController extends Controller
{
    public function getGroup(Request $request, $group_id): array
    {
        return ['group' => AttrGroupResource::find($group_id, $request->all())];
    }

    public function getGroups(Request $request)
    {
        return ['groups' => AttrGroupFilter::fromRequest($request)->paginate()];
    }

    /**
     * Two-pass payload for the Advanced Variation library picker.
     *
     * Pass 1: groups list (capped at 200) plus a per-group terms_count from a
     * single batched aggregate. No terms are eager-loaded — at scale (200
     * groups times hundreds of terms each) the combined payload could exceed
     * memory limits and stall the admin request.
     *
     * Pass 2: the picker calls GET attr/group/{id}/terms on expand to fetch
     * the full paginated term list for the specific group the merchant
     * clicked. This trades one big upfront response for one small expansion
     * round-trip per group the user actually interacts with — the usual
     * pattern at expected catalog sizes (most stores expand 1-3 groups, not
     * all 200).
     */
    public function getLibrary(Request $request): array
    {
        $totalGroups = (int) AttributeGroup::query()->count();

        // Match the Attributes library sidebar: groups follow the merchant's
        // manual drag-order (fct_atts_groups.serial), so the product editor's
        // option-name dropdown lists them in the same order the merchant arranged,
        // not alphabetically. id ASC is the deterministic tie-breaker.
        $groups = AttributeGroup::query()
            ->orderBy('serial', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(200)
            ->get();

        // Stamp the authoritative terms_count from a single batched aggregate so
        // the picker can show "Color - 20 terms" upfront without a second query
        // per group. The full term list is fetched on expand via getTerms().
        if ($groups->isNotEmpty()) {
            $groupIds = $groups->pluck('id')->all();
            $counts   = AttributeTerm::query()
                ->whereIn('group_id', $groupIds)
                ->selectRaw('group_id, COUNT(*) AS c')
                ->groupBy('group_id')
                ->get()
                ->keyBy('group_id');

            $groups->each(function ($group) use ($counts) {
                $row = $counts->get($group->id);
                $group->terms_count = $row ? (int) $row->c : 0;
            });
        }

        return [
            'groups'       => $groups,
            'total_groups' => $totalGroups,
            'cap'          => 200,
        ];
    }

    public function createGroup(AttrGroupRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $arg = Arr::only($data, ['title', 'slug', 'settings']);
        $isCreated = AttrGroupResource::create($arg);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function updateGroup(AttrGroupRequest $request, $group_id)
    {
        $data = $request->getSafe($request->sanitize());
        $arg = Arr::only($data, ['title', 'settings']);
        $isUpdated = AttrGroupResource::update($arg, $group_id);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function deleteGroup(Request $request, $group_id)
    {
        $isDeleted = AttrGroupResource::delete($group_id);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function getTerms(Request $request, $group_id): array
    {
        /** @var AttrTermFilter $filter */
        $filter = AttrTermFilter::fromRequest($request);
        $filter->setGroupId((int) $group_id);
        return ['terms' => $filter->paginate()];
    }

    public function createTerms(AttrTermRequest $request, $group_id)
    {
        $data = $request->getSafe($request->sanitize());

        if (empty(Arr::get($data, 'terms', []))) {
            return $this->response->sendError([
                'message' => __('At least one term is required.', 'fluent-cart')
            ], 422);
        }

        $created = AttrTermResource::create($data, ['group_id' => $group_id]);

        if (is_wp_error($created)) {
            return $created;
        }
        return $this->response->sendSuccess($created);
    }

    public function updateTerm(AttrTermUpdateRequest $request, $group_id, $term_id)
    {
        $data = $request->getSafe($request->sanitize());
        $arg = Arr::only($data, ['title', 'settings']);
        $isUpdated = AttrTermResource::update($arg, $term_id, ['group_id' => $group_id]);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function deleteTerm(Request $request, $group_id, $term_id)
    {
        $isDeleted = AttrTermResource::delete($term_id, ['group_id' => $group_id]);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function reorderTerms(Request $request, $group_id)
    {
        $result = AttrTermResource::reorder([
            'group_id' => $group_id,
            'ids'      => $request->get('ids', []),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }
        return $this->response->sendSuccess($result);
    }

    public function reorderGroups(Request $request)
    {
        // Sanitize: coerce to a clean, deduped list of positive integer IDs. The
        // dedupe keeps AttrGroupResource::reorder's ownership count check from
        // false-tripping on duplicates. This is the single sanitization point —
        // the resource trusts it.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->get('ids', [])),
            function ($id) {
                return $id > 0;
            }
        )));

        // Validate: a reorder is meaningless without IDs (also catches a payload
        // where every entry was 0 / non-numeric and got stripped above).
        if (empty($ids)) {
            return $this->response->sendError([
                'message' => __('No group IDs provided.', 'fluent-cart')
            ], 422);
        }

        // Reject (do NOT silently truncate) an oversized payload so no group's
        // position is lost — a full-list reorder assigns a dense 1..N serial and
        // can't be split into batches. The attribute-group library is a small
        // admin-managed set; the cap is far above any real catalog and bounds the
        // whereIn ownership lookup in AttrGroupResource::reorder.
        $maxGroups = (int) apply_filters('fluent_cart/attribute_groups/max_reorder', 500);
        if (count($ids) > $maxGroups) {
            return $this->response->sendError([
                /* translators: %1$d: maximum number of groups that can be reordered in one request */
                'message' => sprintf(
                    __('Too many groups in the reorder request (maximum %1$d).', 'fluent-cart'),
                    $maxGroups
                )
            ], 422);
        }

        $result = AttrGroupResource::reorder([
            'ids' => $ids,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }
        return $this->response->sendSuccess($result);
    }
}
