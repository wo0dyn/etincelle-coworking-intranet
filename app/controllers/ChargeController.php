<?php
/**
* Charge Controller
*/
class ChargeController extends BaseController
{

    /**
     * Verify if exist
     */
    private function dataExist($id)
    {
        $data = Charge::find($id);
        if (!$data) {
            return Redirect::route('charge_list', 'all')->with('mError', 'Cette charge est introuvable !');
        } else {
            return $data;
        }
    }

    /**
     * List of charge
     */
    public function liste($filtre)
    {
        if (Input::has('type')) {
            Session::put('filtre_charge.type', Input::get('type'));
            if (Input::has('filtre_start')) {
              $date_start_explode = explode('/', Input::get('filtre_start'));
              Session::put('filtre_charge.start', $date_start_explode[2].'-'.$date_start_explode[1].'-'.$date_start_explode[0]);
            }
            if (Input::has('filtre_end')) {
              $date_end_explode = explode('/', Input::get('filtre_end'));
              Session::put('filtre_charge.end', $date_end_explode[2].'-'.$date_end_explode[1].'-'.$date_end_explode[0]);
            } else {
              Session::put('filtre_charge.end', date('Y-m-d'));
            }
        }
        if (Session::has('filtre_charge.type')) {
            $filtre = Session::get('filtre_charge.type');
        }
        if (Session::has('filtre_charge.start')) {
            $date_filtre_start = Session::get('filtre_charge.start');
            $date_filtre_end = Session::get('filtre_charge.end');
        } else {
            $date_filtre_start = date('Y-m').'-01';
            $date_filtre_end = date('Y-m').'-'.date('t', date('m'));
        }

        $setDate = new DateTime();
        $date_now = $setDate->format('Y-m-d');
        $setDate->modify('+8 days');
        $date_deadline = $setDate->format('Y-m-d');
        $q = Charge::orderBy('date_charge', 'DESC');
        switch ($filtre) {
            case 'all':
                $q->whereBetween('date_charge', array($date_filtre_start, $date_filtre_end));
                break;

            case 'deadline_close':
                $q->whereBetween('deadline', array($date_now, $date_deadline))->whereNotNull('deadline')->whereNull('date_payment');
                break;

            case 'deadline_exceeded':
                $q->where('deadline', '<', $date_now)->whereNotNull('deadline')->whereNull('date_payment');
                break;
        }
        $charges = $q->paginate(15);

        return View::make('charge.liste', array('charges' => $charges, 'filtre' => $filtre));
    }

    /**
     * Add charge
     */
    public function add()
    {
        return View::make('charge.add');
    }

    /**
     * Add charge check
     */
    public function add_check()
    {
        $validator = Validator::make(Input::all(), Charge::$rulesAdd);
        if (!$validator->fails()) {
            $date_explode = explode('/', Input::get('date_charge'));
            $date_payment_explode = explode('/', Input::get('date_payment'));
            $deadline_explode = explode('/', Input::get('deadline'));

            $charge = new Charge;
            $charge->date_charge = $date_explode[2].'-'.$date_explode[1].'-'.$date_explode[0];
            if (Input::get('date_payment')) { $charge->date_payment = $date_payment_explode[2].'-'.$date_payment_explode[1].'-'.$date_payment_explode[0]; }
            if (Input::get('deadline')) { $charge->deadline = $deadline_explode[2].'-'.$deadline_explode[1].'-'.$deadline_explode[0]; }
            if (Input::get('organisation_id')) { $charge->organisation_id = Input::get('organisation_id'); }

            if (Input::file('document')) {
                $document = time(true).'.'.Input::file('document')->guessClientExtension();
                if (Input::file('document')->move('uploads/charges', $document)) {
                    $charge->document = $document;
                }
            }

            if ($charge->save()) {
                if (Input::get('tags')) {
                    $tags = Input::get('tags');

                    $tags_keys = array();
                    foreach ($tags as $tag) {
                        if (!array_key_exists($tag, $tags_keys)) {
                            $checkTag = Tag::where('name', '=', $tag)->first();
                            if ($checkTag) {
                                $chargeTag = new ChargeTag;
                                $chargeTag->charge_id = $charge->id;
                                $chargeTag->tag_id = $checkTag->id;

                                if ($chargeTag->save()) {
                                    $tags_keys[$tag] = $tag;
                                }
                            } else if (trim($tag) != '') {
                                $new_tag = new Tag;
                                $new_tag->name = $tag;
                                if ($new_tag->save()) {
                                    $chargeTag = new ChargeTag;
                                    $chargeTag->charge_id = $charge->id;
                                    $chargeTag->tag_id = $new_tag->id;

                                    if ($chargeTag->save()) {
                                        $tags_keys[$tag] = $tag;
                                    }
                                }
                            }
                        }
                    }
                }

                foreach (Input::get('description') as $key => $it) {
                    if ($it) {
                        $item = new ChargeItem;
                        $item->description = $it;
                        $item->amount = Input::get('amount.'.$key);
                        $item->vat_types_id = Input::get('vat_types_id.'.$key);

                        $item->charge()->associate($charge);
                        $item->save();
                    }
                }

                return Redirect::route('charge_modify', $charge->id)->with('mSuccess', 'La charge a bien été ajoutée');
            } else {
                return Redirect::route('charge_add')->with('mError', 'Impossible de créer cette charge')->withInput();
            }
        } else {
            return Redirect::route('charge_add')->with('mError', 'Il y a des erreurs')->withInput()->withErrors($validator->messages());
        }
    }

    /**
     * Modify charge
     */
    public function modify($id)
    {
        $charge = $this->dataExist($id);

        $tags = '';
        foreach ($charge->tags as $k => $tag) {
            if ($k > 0) { $tags .= ','; }
            $tags .= $tag->id;
        }

        return View::make('charge.modify', array('charge' => $charge, 'tags' => $tags));
    }

    /**
     * Modify charge (form)
     */
    public function modify_check($id)
    {
        $charge = $this->dataExist($id);

        $validator = Validator::make(Input::all(), Charge::$rules);
        if (!$validator->fails()) {
            $date_explode = explode('/', Input::get('date_charge'));
            $date_payment_explode = explode('/', Input::get('date_payment'));
            $deadline_explode = explode('/', Input::get('deadline'));

            $charge->date_charge = $date_explode[2].'-'.$date_explode[1].'-'.$date_explode[0];
            if (Input::get('date_payment')) { $charge->date_payment = $date_payment_explode[2].'-'.$date_payment_explode[1].'-'.$date_payment_explode[0]; }
            if (Input::get('deadline')) { $charge->deadline = $deadline_explode[2].'-'.$deadline_explode[1].'-'.$deadline_explode[0]; }
            if (Input::get('organisation_id')) { $charge->organisation_id = Input::get('organisation_id'); }

            if (Input::file('document')) {
                $document = time(true).'.'.Input::file('document')->guessClientExtension();
                if (Input::file('document')->move('uploads/charges', $document)) {
                    if ($charge->document) {
                        unlink(public_path().'/uploads/charges/'.$charge->document);
                    }
                    $charge->document = $document;
                }
            }

            if ($charge->save()) {
                if (Input::get('tags')) {
                    $tags = Input::get('tags');

                    $tags_keys = array();
                    foreach ($tags as $tag) {
                        if (!array_key_exists($tag, $tags_keys)) {
                            $checkTag = Tag::where('name', '=', $tag)->first();
                            if ($checkTag) {
                                if (!ChargeTag::where('charge_id', '=', $charge->id)->where('tag_id', '=', $checkTag->id)->first()) {
                                    $chargeTag = new ChargeTag;
                                    $chargeTag->charge_id = $charge->id;
                                    $chargeTag->tag_id = $checkTag->id;

                                    if ($chargeTag->save()) {
                                        $tags_keys[$tag] = $tag;
                                    }
                                } else {
                                    $tags_keys[$tag] = $tag;
                                }
                            } else if (trim($tag) != '') {
                                $new_tag = new Tag;
                                $new_tag->name = $tag;
                                if ($new_tag->save()) {
                                    $chargeTag = new ChargeTag;
                                    $chargeTag->charge_id = $charge->id;
                                    $chargeTag->tag_id = $new_tag->id;

                                    if ($chargeTag->save()) {
                                        $tags_keys[$tag] = $tag;
                                    }
                                }
                            }
                        }
                    }
                }

                foreach ($charge->items as $item) {
                    ChargeItem::where('id', $item->id)->update(array(
                        'description' => Input::get('description.'.$item->id),
                        'amount' => Input::get('amount.'.$item->id),
                        'vat_types_id' => Input::get('vat_types_id.'.$item->id),
                    ));
                }

                if (Input::get('description.0')) {
                    $item = new ChargeItem;
                    $item->insert(array(
                        'charge_id' => $id,
                        'description' => Input::get('description.0'),
                        'amount' => Input::get('amount.0'),
                        'vat_types_id' => Input::get('vat_types_id.0')
                    ));
                }

                if (Input::get('payment_description.0')) {
                    $date_payment_explode = explode('/', Input::get('payment_date.0'));
                    $payment = new ChargePayment;
                    $payment->insert(array(
                        'charge_id' => $id,
                        'description' => Input::get('payment_description.0'),
                        'amount' => Input::get('payment_amount.0'),
                        'mode' => Input::get('payment_mode.0'),
                        'date_payment' => $date_payment_explode[2].'-'.$date_payment_explode[1].'-'.$date_payment_explode[0]
                    ));
                }
                return Redirect::route('charge_modify', $charge->id)->with('mSuccess', 'Cette charge a bien été modifiée');
            } else {
                return Redirect::route('charge_modify', $charge->id)->with('mError', 'Impossible de modifier cette charge')->withInput();
            }
        } else {
            return Redirect::route('charge_modify', $charge->id)->with('mError', 'Il y a des erreurs')->withErrors($validator->messages())->withInput();
        }
    }

    /**
     * Delete a charge
     */
    public function delete($id)
    {
        $charge = $this->dataExist($id);

        ChargeTag::where('charge_id', '=', $id)->delete();
        ChargeItem::where('charge_id', '=', $id)->delete();
        if (Charge::destroy($id)) {
            if ($charge->document) {
                unlink(public_path().'/uploads/charges/'.$charge->document);
            }
            return Redirect::route('charge_list', 'all')->with('mSuccess', 'La charge a bien été supprimée');
        } else {
            return Redirect::route('charge_modify', $id)->with('mError', 'Impossible de supprimer cette charge');
        }
    }

    /**
     * Duplicate a charge
     */
    public function duplicate($id)
    {
      $charge = $this->dataExist($id);
      
      $newCharge = new Charge;
      $newCharge->date_charge = date('Y-m-d');
      $date = new DateTime($newCharge->date_charge);
      $date->modify('+1 month');
      $newCharge->deadline = $date->format('Y-m-d');
      $newCharge->organisation_id = $charge->organisation_id;

      if ($newCharge->save()) {
        foreach ($charge->items as $item) {
          $addItem = new ChargeItem;
          $addItem->insert(array(
              'charge_id' => $newCharge->id,
              'description' => $item->description,
              'amount' => $item->amount,
              'vat_types_id' => $item->vat_types_id
          ));
        }

        foreach ($charge->tags as $tag) {
          $chargeTag = new ChargeTag;
          $chargeTag->charge_id = $newCharge->id;
          $chargeTag->tag_id = $tag->id;
          $chargeTag->save();
        }

        return Redirect::route('charge_modify', $newCharge->id);
      }
    }
}
