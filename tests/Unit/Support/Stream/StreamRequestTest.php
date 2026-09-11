<?php

namespace Tests\Unit\Support\Stream;

use App\Support\Stream\StreamRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

class StreamRequestTest extends TestCase
{
    public function test_before_is_read_from_the_query_and_a_bad_one_is_the_head(): void
    {
        $this->assertSame(5, StreamRequest::before(Request::create('/t?before=2026-03-01T12:34:56%2B09:00|5'))->id);
        $this->assertNull(StreamRequest::before(Request::create('/t?before=nonsense')));
        $this->assertNull(StreamRequest::before(Request::create('/t?before[]=x')));
        $this->assertNull(StreamRequest::before(Request::create('/t')));
    }

    public function test_a_legacy_page_beyond_the_first_redirects_to_the_head_keeping_other_parameters(): void
    {
        $redirect = StreamRequest::legacyPageRedirect(Request::create('http://sns.test/timeline?page=3&per_page=5'));

        $this->assertSame(302, $redirect->getStatusCode());
        $this->assertSame('/timeline?per_page=5', $redirect->getTargetUrl());
    }

    public function test_the_first_page_and_no_page_pass_through(): void
    {
        $this->assertNull(StreamRequest::legacyPageRedirect(Request::create('/timeline?page=1')));
        $this->assertNull(StreamRequest::legacyPageRedirect(Request::create('/timeline')));
    }

    public function test_a_page_that_is_not_a_number_still_redirects(): void
    {
        $this->assertSame('/timeline', StreamRequest::legacyPageRedirect(Request::create('/timeline?page=abc'))->getTargetUrl());
        $this->assertSame('/timeline?before=x', StreamRequest::legacyPageRedirect(Request::create('/timeline?before=x&page=2'))->getTargetUrl());
    }
}
