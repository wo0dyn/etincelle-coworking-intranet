@extends('layouts.master')

@section('meta_title')
	Ajout
    @if ($type == 'F')
    d'une facture
    @elseif ($type == 'D')
    d'un devis
    @endif
@stop

@section('content')
    @if ($errors->has())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            {{ $error }}<br>
        @endforeach
    </div>
    @endif

	<h1>
        @if ($type == 'F')
        Nouvelle facture
        @elseif ($type == 'D')
        Nouveau devis
        @endif
    </h1>
	{{ Form::open(array('route' => array('invoice_add_check', $type))) }}
        {{ Form::hidden('type', $type) }}
		<input type="hidden" name="last_orga" id="oldOrganisation" value="{{ $last_organisation_id }}">
        {{ Form::label('user_id', 'Client') }}
		<p>{{ Form::select('user_id', User::Select('Sélectionnez un client'), null, array('id' => 'selectUserId', 'class' => 'form-control')) }}</p>
        {{ Form::label('organisation_id', 'Organisation') }}
		<p>{{ Form::select('organisation_id', array(), null, array('id' => 'selectOrganisationId', 'class' => 'form-control')) }}</p>
        {{ Form::label('address', 'Adresse de facturation') }}
        <p>{{ Form::textarea('address', null, array('id' => 'addressInvoice', 'class' => 'form-control', 'rows' => '5')) }}</p>
        {{ Form::label('date_invoice', 'Date de facturation') }}
		<p>{{ Form::text('date_invoice', date('d/m/Y'), array('class' => 'form-control datePicker')) }}</p>
		<p>{{ Form::submit('Ajouter', array('class' => 'btn btn-success')) }}</p>
	{{ Form::close() }}
@stop

@section('javascript')
<script type="text/javascript">
$().ready(function(){
	var oldOrganisation = $('#oldOrganisation').val();
	function getListOrganisations(id) {
		var url = "{{ URL::route('user_json_organisations') }}";
		var urlFinale = url.replace("%7Bid%7D", id);

		$('#selectOrganisationId').html('');
		$.getJSON(urlFinale, function(data) {
			var items = '';
			$.each( data, function(key, val) {
				if (oldOrganisation == key) {
					items = items + '<option value="' + key + '" selected>' + val + '</option>';
				} else {
					items = items + '<option value="' + key + '">' + val + '</option>';
				}
			});

			$('#selectOrganisationId').html(items);
            getDataOrganisation($('#selectOrganisationId').val());
		});
	}

    function getDataOrganisation(id) {
        var url = "{{ URL::route('organisation_json_infos') }}";
        var urlFinale = url.replace("%7Bid%7D", id);

        $.getJSON(urlFinale, function(data) {
            $.each( data, function(key, val) {
                $('#addressInvoice').html(val);
            });
        });
    }

	$('#selectUserId').on('change', function(e) {
		getListOrganisations($(this).val());
	});
    $('#selectOrganisationId').on('change', function(e) {
        getDataOrganisation($(this).val());
    });

	getListOrganisations($('#selectUserId').val());

    $('.datePicker').datepicker();
});
</script>
@stop