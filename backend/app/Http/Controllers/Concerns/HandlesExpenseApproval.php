<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Reusable controller actions for the Expenses sub-modules' shared approval
 * workflow (submit → approve / reject, mark-paid, bill attachment). The host
 * controller declares its model relations and attachment folder, and exposes
 * thin route methods that delegate here.
 *
 * @see HasApprovalWorkflow the model-side transitions this drives.
 */
trait HandlesExpenseApproval
{
    /** Eager-load list used when returning a single record. */
    abstract protected function relations(): array;

    /** Public-disk folder scanned bills/receipts are stored under. */
    abstract protected function attachmentDir(): string;

    /** Wrap a fresh model in the module's API resource. */
    abstract protected function resource(Model $model);

    protected function doSubmit(Model $model)
    {
        if (! $model->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be submitted.'], 422);
        }
        $model->markSubmitted();

        return $this->resource($model->load($this->relations()));
    }

    protected function doApprove(Request $request, Model $model)
    {
        if ($model->status !== 'pending') {
            return response()->json(['message' => 'Only pending records can be approved.'], 422);
        }
        $model->markApproved($request->user()?->id);

        return $this->resource($model->load($this->relations()));
    }

    protected function doReject(Request $request, Model $model)
    {
        if ($model->status !== 'pending') {
            return response()->json(['message' => 'Only pending records can be rejected.'], 422);
        }
        $data = $request->validate(['rejection_reason' => ['nullable', 'string', 'max:1000']]);
        $model->markRejected($request->user()?->id, $data['rejection_reason'] ?? null);

        return $this->resource($model->load($this->relations()));
    }

    protected function doMarkPaid(Request $request, Model $model)
    {
        if ($model->status !== 'approved') {
            return response()->json(['message' => 'Only approved records can be marked paid.'], 422);
        }
        $model->setPaid($request->boolean('paid', true));

        return $this->resource($model->load($this->relations()));
    }

    protected function doUploadAttachment(Request $request, Model $model)
    {
        $request->validate([
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        if ($model->attachment_path) {
            Storage::disk('public')->delete($model->attachment_path);
        }
        $path = $request->file('attachment')->store($this->attachmentDir(), 'public');
        $model->update(['attachment_path' => $path]);

        return $this->resource($model->load($this->relations()));
    }

    /** Delete a record's stored attachment, if any. Call from destroy(). */
    protected function deleteAttachment(Model $model): void
    {
        if ($model->attachment_path) {
            Storage::disk('public')->delete($model->attachment_path);
        }
    }
}
