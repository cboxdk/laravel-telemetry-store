<?php

declare(strict_types=1);

use Cbox\TelemetryStore\Read\Histogram;
use Cbox\TelemetryStore\Read\Labels;
use Cbox\TelemetryStore\Read\MetricName;
use Cbox\TelemetryStore\Read\TraceFields;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;

it('compiles trace conditions to ClickHouse expressions', function (): void {
    expect(TraceFields::condition(TraceCondition::eq('span.http.route', '/orders')))
        ->toBe("SpanAttributes['http.route'] = '/orders'");

    expect(TraceFields::condition(TraceCondition::token('status', TraceOp::Eq, 'error')))
        ->toBe("StatusCode = 'Error'");

    expect(TraceFields::condition(TraceCondition::token('kind', TraceOp::Eq, 'server')))
        ->toBe("SpanKind = 'Server'");

    expect(TraceFields::condition(TraceCondition::nil('span.exception.type')))
        ->toBe("SpanAttributes['exception.type'] != ''");

    expect(TraceFields::condition(TraceCondition::token('duration', TraceOp::Gt, '100ms')))
        ->toBe('Duration > 100000000');

    expect(TraceFields::condition(TraceCondition::token('span.http.response.status_code', TraceOp::Gte, '500')))
        ->toBe("toInt64OrZero(SpanAttributes['http.response.status_code']) >= 500");

    expect(TraceFields::condition(TraceCondition::eq('resource.service.name', 'web')))
        ->toBe("ServiceName = 'web'");
});

it('maps log labels between snake_case and dotted keys', function (): void {
    expect(Labels::logExpression('service_name'))->toBe('ServiceName');
    expect(Labels::logExpression('level'))->toBe('SeverityText');
    expect(Labels::logExpression('exception_group'))
        ->toBe("if(mapContains(LogAttributes, 'exception.group'), LogAttributes['exception.group'], ResourceAttributes['exception.group'])");

    $labels = Labels::fromLogRow([
        'ServiceName' => 'checkout',
        'TraceId' => 't1',
        'SeverityText' => 'error',
        'LogAttributes' => ['exception.group' => 'g1'],
        'ResourceAttributes' => ['deployment.environment.name' => 'prod'],
    ]);

    expect($labels)->toMatchArray([
        'service_name' => 'checkout',
        'trace_id' => 't1',
        'level' => 'error',
        'exception_group' => 'g1',           // snake alias for the card
        'exception.group' => 'g1',           // dotted for analytics probes
        'deployment_environment_name' => 'prod',
    ]);
});

it('routes metric names to the right table', function (): void {
    expect(MetricName::histogramPart('http_server_request_duration_milliseconds_bucket'))->toBe('bucket');
    expect(MetricName::base('http_server_request_duration_milliseconds_bucket'))->toBe('http_server_request_duration_milliseconds');
    expect(MetricName::table('http_server_request_duration_milliseconds_bucket', true))->toBe('otel_metrics_histogram');
    expect(MetricName::table('queue_jobs_processed_total', true))->toBe('otel_metrics_sum');
    expect(MetricName::table('queue_depth', false))->toBe('otel_metrics_gauge');
});

it('computes histogram quantiles by linear interpolation', function (): void {
    // 10 observations in [0,10], 10 in (10,20]; p50 sits at the top of bucket 0.
    expect(Histogram::quantile([10.0, 10.0, 0.0], [10.0, 20.0], 0.5))->toBe(10.0);
    // p75 → halfway up the second bucket → 15.
    expect(Histogram::quantile([10.0, 10.0, 0.0], [10.0, 20.0], 0.75))->toBe(15.0);
    // empty histogram → NAN.
    expect(is_nan(Histogram::quantile([], [], 0.95)))->toBeTrue();
});
