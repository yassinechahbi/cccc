<?php

namespace App\Http\Controllers;

use App\Models\Circuit;
use App\Support\QrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CircuitPdfController extends Controller
{
    /** Printable circuit form (front + backside), sent by the OM with the first cover. */
    public function __invoke(Request $request, Circuit $circuit): Response
    {
        Gate::authorize('edit-circuit', $circuit); // printing is the OM's job

        $paper = $request->query('paper') === 'letter' ? 'letter' : 'a4';

        $circuit->load('legs.member.country', 'originatingMember');

        $qr = $circuit->legs->mapWithKeys(fn ($leg) => [$leg->id => QrCode::pngDataUri($leg->trackingUrl())]);

        return Pdf::loadView('pdf.circuit', [
            'circuit' => $circuit,
            'qr' => $qr,
            'trackHost' => preg_replace('#^https?://(www\.)?#', '', route('track')),
        ])
            ->setPaper($paper)
            ->stream("cccc-circuit-{$circuit->number}.pdf");
    }
}
