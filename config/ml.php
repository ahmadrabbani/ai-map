<?php

return [
    'lightgbm' => [
        'enabled' => (bool) env('ML_LIGHTGBM_ENABLED', false),
        'python_bin' => env('ML_LIGHTGBM_PYTHON_BIN', 'python3'),
        'predict_script' => env('ML_LIGHTGBM_PREDICT_SCRIPT', base_path('scripts/ml/predict_rule_risk.py')),
        'model_path' => env('ML_LIGHTGBM_MODEL_PATH', base_path('storage/app/ml/lightgbm_rule_risk.txt')),
        'timeout_seconds' => (int) env('ML_LIGHTGBM_TIMEOUT_SECONDS', 5),
    ],
    'imagery' => [
        'enabled' => (bool) env('ML_IMAGERY_ENABLED', false),
        'python_bin' => env('ML_IMAGERY_PYTHON_BIN', 'python3'),
        'predict_script' => env('ML_IMAGERY_PREDICT_SCRIPT', base_path('scripts/ml/predict_imagery_signal.py')),
        'train_script' => env('ML_IMAGERY_TRAIN_SCRIPT', base_path('scripts/ml/train_imagery_signal.py')),
        'model_path' => env('ML_IMAGERY_MODEL_PATH', base_path('storage/app/ml/imagery/imagery_signal_model.json')),
        'timeout_seconds' => (int) env('ML_IMAGERY_TIMEOUT_SECONDS', 5),
        'static_zoom' => (int) env('ML_IMAGERY_STATIC_ZOOM', 20),
        'static_size' => env('ML_IMAGERY_STATIC_SIZE', '512x512'),
        'static_maptype' => env('ML_IMAGERY_STATIC_MAPTYPE', 'satellite'),
    ],
];
