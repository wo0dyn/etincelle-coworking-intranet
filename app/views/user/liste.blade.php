@extends('layouts.master')

@section('meta_title')
    Utilisateurs
@stop

@section('breadcrumb')
    <div class="row wrapper border-bottom white-bg page-heading">
        <div class="col-sm-4">
            <h2>Utilisateurs</h2>
        </div>
        <div class="col-sm-8">
            @if (Auth::user()->isSuperAdmin())
                <div class="title-action">
                    <a href="{{ URL::route('user_add') }}" class="btn btn-primary">Ajouter un utilisateur</a>
                </div>
            @endif
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-12">
            <div class="ibox">
                <div class="ibox-title">
                    <h5>Filtre</h5>

                    {{--<div class="ibox-tools">--}}
                    {{--<a class="collapse-link">--}}
                    {{--<i class="fa fa-chevron-up"></i>--}}
                    {{--</a>--}}
                    {{--</div>--}}
                </div>
                <div class="ibox-content">
                    <div class="row">
                        {{ Form::open(array('route' => array('user_filter'))) }}
                        {{ Form::hidden('filtre_submitted', 1) }}
                        @if (Auth::user()->isSuperAdmin())
                            <div class="col-md-4">
                                {{ Form::select('filtre_user_id', User::Select('Sélectionnez un client'), Session::get('filtre_user.user_id') ? Session::get('filtre_user.user_id') : null, array('id' => 'filter-client','class' => 'form-control')) }}
                            </div>
                        @else
                            {{ Form::hidden('filtre_user_id', Auth::user()->id) }}
                        @endif

                        <div class="col-md-2 input-group-sm">
                            {{ Form::checkbox('filtre_member', true, Session::has('filtre_user.member') ? Session::get('filtre_user.member') : false) }}
                            Membre
                        </div>
                        <div class="col-md-2 input-group-sm">
                            {{ Form::checkbox('filtre_subscription', true, Session::has('filtre_user.subscription') ? Session::get('filtre_user.subscription') : false) }}
                            Souscription active
                        </div>
                        <div class="col-md-2">
                            {{ Form::submit('Filtrer', array('class' => 'btn btn-sm btn-primary')) }}
                            <a href="{{URL::route('user_filter_reset')}}"
                               class="btn btn-sm btn-default">Réinitialiser</a>
                        </div>
                        {{ Form::close() }}
                    </div>
                </div>
            </div>
        </div>
    </div>


    <table class="table table-striped table-hover">
        <thead>
        <tr>
            <th class="col-md-5">Nom</th>
            <th class="col-md-1">Membre</th>
            <th class="col-md-2">Abonnement</th>
            <th class="col-md-2">Temps passé</th>
            <th class="col-md-2">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($users as $user)
            <tr>
                <td>
                    <a href="{{ URL::route('user_modify', $user->id) }}">{{ $user->fullnameOrga }}</a>
                </td> <td>
                    <?php
                        if($user->is_member){
                            echo 'Oui';
                        }else{
                            echo '-';
                        }
                        ?>
                </td>
                <td>
                    <?php
                    $subscription = $user->getLastSubscription();

                    if (!$subscription) {
                        printf('-');
                    } else {

                        $remainingDays = (strtotime($subscription['subscription_to']) - time()) / (24 * 3600);

                        if ($remainingDays < -30) {
                            $status = 'badge badge-plain';
                        } elseif ($remainingDays < 0) {
                            $status = 'badge badge-danger';
                        } elseif ($remainingDays < 7) {
                            $status = 'badge badge-warning';
                        } elseif ($remainingDays < 7) {
                            $status = 'badge badge-success';
                        }else{
                            $status = '';
                        }
                        $ratio = '';
                        $duration = 0;
                        if ($subscription['subscription_hours_quota'] != 0) {
                            $duration = $user->getCoworkingTimeSpent($subscription['subscription_from'], $subscription['subscription_to']);
                            if ($subscription['subscription_hours_quota'] != -1) {
                                $ratio = sprintf(' (%d%%)',
                                        100 * $duration / ($subscription['subscription_hours_quota'] * 60));
                            }
                        }
                        printf('<span class="%s">%s</span>%s', $status,
                                date('d/m/Y', strtotime($subscription['subscription_to'])), $ratio);

                    }

                    ?>
                </td>
                <td>
                    <?php
                    if ($subscription) {

                        if ($duration) {
                            echo durationToHuman($duration);
                        } else {
                            echo '0';
                        }
                        if ($subscription['subscription_hours_quota'] == -1) {
                            echo ' / Illimité';
                        } else {
                            printf(' / %d heures', $subscription['subscription_hours_quota']);
                        }

                    }
                    ?>
                </td>
                <td>
                    <a href="{{ URL::route('user_profile', $user->id) }}"
                       class="btn btn-xs btn-primary">Voir</a>
                    <a href="{{ URL::route('user_modify', $user->id) }}"
                       class="btn btn-xs btn-default">Modifier</a>
                </td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td colspan="5">{{ $users->links() }}</td>
        </tr>
        </tfoot>
    </table>
@stop
