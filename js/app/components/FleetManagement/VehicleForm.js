import React, { useState } from 'react'
import CompactPicker from 'react-color/lib/Compact'

import { Field, Formik } from 'formik'
import _ from 'lodash'
import { useTranslation } from 'react-i18next'

export default ({initialValues, onSubmit, warehouses, closeModal}) => {

  const { t } = useTranslation()

  const [isLoading, setLoading] = useState(false)

  const handleSubmit = async (values) => {
    setLoading(true)
    await onSubmit(values)
    setLoading(false)
    closeModal()
  }

  initialValues = {
    ...initialValues,
    // FIXME : not 100% sure it is the correct way to do this...
    isElectric: initialValues.isElectric || false,
    warehouse: initialValues.warehouse || warehouses[0]['@id']
  }

  const validate = (values) => {
    // we use mostly buil-int HTML validation
    const errors = {}

    if(values.isElectric && !values.electricRange) {
      errors.electricRange = t('ADMIN_DASHBOARD_VEHICLE_FORM_REQUIRES_ELECTRIC_RANGE')
    }

  }

  return (
    <div>
      <div className="modal-header">
        <h4 className="modal-title">
          { t('ADMIN_DASHBOARD_VEHICLE_FORM_TITLE') }
          <a className="pull-right" onClick={ () => closeModal() }><i className="fa fa-close"></i></a>
        </h4>
      </div>
      <Formik
        initialValues={initialValues}
        validate={validate}
        onSubmit={handleSubmit}
        validateOnBlur={false}
        validateOnChange={false}
      >
        {({
          values,
          errors,
          touched,
          setFieldValue,
          handleSubmit,
        }) => (
          <form
            onSubmit={handleSubmit}
            autoComplete="off"
            className="p-4"
          >
            <div className={ `form-group${errors.name ? 'has-error': ''}` }>
              <div className="row">
                <div className="col-md-8">
                <label className="control-label" htmlFor="name">{ 'ADMIN_VEHICLE_NAME_LABEL' }</label>
                  <Field
                    className="form-control"
                    type="text"
                    value={ values.name }
                    name="name"
                    minLength="2"
                    required
                  />
                  { errors.name && touched.name && (
                    <div className="has-error px-4">
                      <small className="help-block">{ errors.name }</small>
                    </div>
                  )}
                </div>
              </div>
            </div>
            <div className={ `form-group ${errors.color ? 'has-error': ''}` }>
              <label className="control-label" htmlFor="maxWeight">{ 'ADMIN_VEHICLE_COLOR_LABEL' }</label>
                <div className="row">
                  <div className="col-md-8">
                    <Field
                      name="color"
                      minLength="7"
                      maxlength="7"
                      pattern="#[\d\w]{6}"
                      required
                    >
                      {() => (
                        <CompactPicker
                          color={ values.color }
                          onChangeComplete={ color => {
                            setFieldValue('color', color.hex)
                          }} />
                      )}
                    </Field>
                    { errors.color && touched.color && (
                      <div className="has-error px-4">
                        <small className="help-block">{ errors.color }</small>
                      </div>
                    )}
                  </div>
                </div>
              </div>
              <div className="row form-inline">
                <div className="col-md-2">
                  <div className={ `form-group ${errors.maxWeight ? 'has-error': ''}` }>
                    <label className="control-label" htmlFor="maxWeight">{ 'ADMIN_VEHICLE_MAX_WEIGHT_LABEL' }</label>
                    <Field
                      className="form-control"
                      type="number"
                      value={ values.maxWeight }
                      name="maxWeight"
                      required
                    />
                    { errors.maxWeight && touched.maxWeight && (
                      <div className="has-error px-4">
                        <small className="help-block">{ errors.maxWeight }</small>
                      </div>
                    )}
                  </div>
                </div>
                <div className="col-md-offset-4 col-md-2">
                  <div className={ `form-group ${errors.volumeUnits ? 'has-error': ''}` }>
                    <label className="control-label" htmlFor="volumeUnits">{ 'ADMIN_VEHICLE_VOLUME_UNITS_LABEL' }</label>
                    <Field
                      className="form-control"
                      type="number"
                      value={ values.volumeUnits }
                      name="volumeUnits"
                      required
                    />
                    { errors.volumeUnits && touched.volumeUnits && (
                      <div className="has-error px-4">
                        <small className="help-block">{ errors.volumeUnits }</small>
                      </div>
                    )}
                  </div>
                </div>
            </div>
            <div className="row">
              <div className="col-md-2">
                <div className={ `form-group ${errors.isElectric ? 'has-error': ''}` }>
                  <label className="control-label" htmlFor="isElectric">{ 'ADMIN_VEHICLE_IS_ELECTRIC_LABEL' }</label>
                  <Field
                    type="checkbox"
                    name="isElectric"
                    required
                  />
                  { errors.isElectric && touched.isElectric && (
                    <div className="has-error px-4">
                      <small className="help-block">{ errors.isElectric }</small>
                    </div>
                  )}
                </div>
              </div>
              <div className="col-md-4 col-md-offset-2">
                { values.isElectric ?
                  <div className={ `form-group ${errors.electricRange ? 'has-error': ''}` }>
                    <label className="control-label" htmlFor="electricRange">{ 'ADMIN_VEHICLE_ELECTRIC_RANGE_LABEL' }</label>
                    <Field
                      className="form-control"
                      type="number"
                      value={ values.electricRange }
                      name="electricRange"
                    />
                    { errors.electricRange && touched.electricRange && (
                      <div className="has-error px-4">
                        <small className="help-block">{ errors.electricRange }</small>
                      </div>
                    )}
                  </div>
                  : null
                }
              </div>
            </div>
            <div className="row">
              <div className="col-md-8">
                <div className={ `form-group ${errors.warehouse ? 'has-error': ''}` }>
                  <label className="control-label" htmlFor="warehouse">{ 'ADMIN_VEHICLE_WAREHOUSE_LABEL' }</label>
                  <Field
                    className="form-control"
                    as="select"
                    value={ values.warehouse }
                    name="warehouse"
                  >
                    {_.map(warehouses, (warehouse) => {
                      return (
                        <option key={warehouse['@id']} value={warehouse['@id']}>{warehouse.name}</option>
                      )
                    })}
                  </Field>
                  { errors.warehouse && touched.warehouse && (
                    <div className="has-error px-4">
                      <small className="help-block">{ errors.warehouse }</small>
                    </div>
                  )}
                </div>
              </div>
            </div>
            <div className="row">
              <div className="col-md-8 col-md-offset-2">
                <div className="input-group-btn">
                  <button className="btn btn-primary" type="submit" disabled={isLoading}>
                    { 'ADMIN_DASHBOARD_SAVE' }
                  </button>
                </div>
              </div>
            </div>
          </form>
        )}
      </Formik>
    </div>
  )
}