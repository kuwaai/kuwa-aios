<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LLMs;
use App\Models\Permissions;
use Illuminate\Support\Facades\Storage;
use DB;

class ModelPrune extends Command
{
    protected $signature = 'model:prune {--force : Automatically confirm deletion} {--exclude=* : Exclude specific access codes} {--interact : Choose which model to be delete interactively}';
    protected $description = 'Quickly cleanup all models';
    public function __construct()
    {
        parent::__construct();
    }
    public function handle()
    {
        $always_exclude = [".tool/kuwa/weblet"];
        $models = LLMs::whereNotIn('access_code', array_merge($this->option('exclude'), $always_exclude))->get();

        if ($models->isEmpty()) {
            $this->info('No models found for deletion.');
            return;
        }

        if ($this->option('interact')) {
            $model_to_be_delete = [];
            foreach ($models as $model) {
                if($this->confirm("Delete this model?\nID: " . $model->id . ', name: ' . $model->name . ', access_code: ' . $model->access_code . ', description: ' . $model->description)){
                    array_push($model_to_be_delete, $model);
                }
            }
            $models = $model_to_be_delete;
        }

        $this->info('The following models will be deleted:');
        foreach ($models as $model) {
            $this->info('- ID: ' . $model->id . ', name: ' . $model->name . ', access_code: ' . $model->access_code . ', description: ' . $model->description);
        }
        if ($this->option('force')) {
            $this->info('Automatically confirming deletion.');
        } elseif (!$this->confirm('Confirm deletion?')) {
            $this->info('Operation cancelled. No models have been deleted.');
            return;
        }
        try {
            DB::beginTransaction(); // Start a database transaction

            foreach ($models as $model) {
                if ($model->image) Storage::delete($model->image);
                Permissions::where('name', '=', 'model_' . $model->id)->delete();
                $model->delete();
            }

            DB::commit();
            $this->info(count($models).' models have been deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction in case of an exception
            $this->error('An error occurred while deleting models. Transaction rolled back.');
            $this->error($e->getMessage());
        }
    }
}
