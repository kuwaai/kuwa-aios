import prometheus_client


class ExecutorMetrics:
    metrics_template = {
        "state": {
            "type": "Enum",
            "description": "The state of the layout.",
            "states": ["idle", "busy"],
        },
        "failed": {
            "type": "Counter",
            "description": "Number of failed requests.",
        },
        "process_time_seconds": {
            "type": "Histogram",
            "description": "Time consumed to process single request with unit: Seconds.",
            "buckets": [
                0.005,
                0.01,
                0.025,
                0.05,
                0.075,
                0.1,
                0.25,
                0.5,
                0.75,
                1.0,
                2.5,
                5.0,
                7.5,
                10.0,
                20.0,
                30.0,
                40.0,
                50.0,
                60.0,
                float("inf"),
            ],
        },
        "output_length_charters": {
            "type": "Histogram",
            "description": "The length of the output text with unit: Charters.",
            "buckets": [
                10,
                20,
                30,
                40,
                50,
                60,
                70,
                80,
                90,
                100,
                200,
                300,
                400,
                500,
                600,
                700,
                800,
                900,
                1000,
                1100,
                1200,
                1300,
                1400,
                1500,
                1600,
                1700,
                1800,
                1900,
                2000,
                float("inf"),
            ],
        },
        "output_throughput_charters_per_second": {
            "type": "Histogram",
            "description": "The throughput of output text with unit: Charters/Second.",
            "buckets": [
                1,
                5,
                10,
                20,
                30,
                40,
                50,
                60,
                70,
                80,
                90,
                100,
                200,
                float("inf"),
            ],
        },
    }

    def __init__(self, executor_name=None):
        self.name_space = "executor"
        self.subsystem = "framework"
        self.executor_name = executor_name

        for name, spec in self.metrics_template.items():
            assert "type" in spec
            type_class = getattr(prometheus_client, spec.pop("type"))
            description = spec.pop("description", "")
            metric = type_class(
                namespace=self.name_space,
                subsystem=self.subsystem,
                name=name,
                labelnames=("executor_name",),
                documentation=description,
                **spec,
            ).labels(self.executor_name)
            setattr(self, name, metric)
