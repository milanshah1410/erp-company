<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappMessageResource;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;

/**
 * WhatsApp messaging. This ships with click-to-chat (wa.me deep links) which
 * works with no third-party account: the server builds the link + logs the
 * message, the operator's device sends it. The `channel`/`status` columns and
 * the WhatsApp settings leave room to bolt on a Business Cloud API sender later
 * without changing the surface here.
 */
class WhatsappController extends Controller
{
    /** Outbound message log, newest first. */
    public function index(Request $request)
    {
        $query = WhatsappMessage::query()->with('customer:id,name');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('template')) {
            $query->where('template', $request->query('template'));
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('phone', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"));
        }

        $sort = $request->query('sort');
        if ($sort && in_array($sort, ['phone', 'template', 'status', 'created_at'], true)) {
            $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('id');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return WhatsappMessageResource::collection($query->paginate($perPage));
    }

    /**
     * Build a wa.me link for a message, log it, and return the link so the
     * client can open the chat. If customer_id is given and no phone, the
     * customer's phone is used.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'template' => ['nullable', 'string', 'max:50'],
            'body' => ['required', 'string'],
        ]);

        $phone = $validated['phone'] ?? null;
        if (! $phone && ! empty($validated['customer_id'])) {
            $phone = Customer::query()->whereKey($validated['customer_id'])->value('phone');
        }
        if (! $phone) {
            return response()->json(['message' => 'A phone number is required to send a WhatsApp message.'], 422);
        }

        $normalized = $this->normalizePhone($phone);
        $link = 'https://wa.me/'.$normalized.'?text='.rawurlencode($validated['body']);

        $message = WhatsappMessage::create([
            'customer_id' => $validated['customer_id'] ?? null,
            'phone' => $normalized,
            'template' => $validated['template'] ?? 'manual',
            'body' => $validated['body'],
            'channel' => 'click_to_chat',
            'status' => 'sent',
            'link' => $link,
            'created_by' => $request->user()?->id,
        ]);

        return (new WhatsappMessageResource($message->load('customer')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(WhatsappMessage $whatsappMessage)
    {
        $whatsappMessage->delete();

        return response()->json(['message' => 'Message deleted.']);
    }

    /**
     * Strip non-digits and prepend the default country code for bare local
     * numbers so wa.me resolves them (10-digit numbers get the tenant default).
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $cc = preg_replace('/\D+/', '', Setting::get('whatsapp_country_code', '91')) ?: '91';

        if (strlen($digits) <= 10) {
            $digits = $cc.$digits;
        }

        return $digits;
    }
}
