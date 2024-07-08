import React, { useState } from 'react'
import CompactPicker from 'react-color/lib/Compact'

import { Field, Formik } from 'formik'
import Select from 'react-select'
import { useTranslation } from 'react-i18next'

export default ({initialValues, onSubmit, vehicles, closeModal}) => {

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
  }

  const validate = (values) => {
    // we use mostly buil-int HTML validation
    const errors = {}

    if(!values.color || (values.isElectric && !values.electricRange)) {
      errors.electricRange = t('ADMIN_FORM_REQUIRED')
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
                <label className="control-label" htmlFor="name">{ 'ADMIN_TRAILER_NAME_LABEL' }</label>
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
              <label className="control-label" htmlFor="maxWeight">{ 'ADMIN_TRAILER_COLOR_LABEL' }</label>
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
              <div className="row form-inline">
                <div className="col-md-2">
                  <div className={ `form-group ${errors.maxWeight ? 'has-error': ''}` }>
                    <label className="control-label" htmlFor="maxWeight">{ 'ADMIN_TRAILER_MAX_WEIGHT_LABEL' }</label>
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
                  <div className={ `form-group ${errors.maxVolumeUnits ? 'has-error': ''}` }>
                    <label className="control-label" htmlFor="maxVolumeUnits">{ 'ADMIN_TRAILER_VOLUME_UNITS_LABEL' }</label>
                    <Field
                      className="form-control"
                      type="number"
                      value={ values.maxVolumeUnits }
                      name="maxVolumeUnits"
                      required
                    />
                    { errors.maxVolumeUnits && touched.maxVolumeUnits && (
                      <div className="has-error px-4">
                        <small className="help-block">{ errors.maxVolumeUnits }</small>
                      </div>
                    )}
                  </div>
                </div>
            </div>
            <div className="row">
              <div className="col-md-2">
                <div className={ `form-group ${errors.isElectric ? 'has-error': ''}` }>
                  <label className="control-label" htmlFor="isElectric">{ 'ADMIN_TRAILER_IS_ELECTRIC_LABEL' }</label>
                  <Field
                    type="checkbox"
                    name="isElectric"
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
                    <label className="control-label" htmlFor="electricRange">{ 'ADMIN_TRAILER_ELECTRIC_RANGE_LABEL' }</label>
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
                <div className={ `form-group ${errors.compatibleVehicles ? 'has-error': ''}` }>
                  <label className="control-label" htmlFor="compatibleVehicles">{ 'ADMIN_TRAILER_COMPATIBLE_VEHICLES_LABEL' }</label>
                  <Field
                    className="form-control"
                    name="compatibleVehicles"
                  >
                  { () =>
                    <Select
                      isMulti={true}
                      // https://github.com/coopcycle/coopcycle-web/issues/774
                      // https://github.com/JedWatson/react-select/issues/3030
                      menuPortalTarget={document.body}
                      options={vehicles.map(vehicle => {return {value: vehicle['@id'], label: vehicle.name}})}
                      onChange={(selected) => { setFieldValue('compatibleVehicles', selected.map(opt => opt.value)) }}
                      placeholder={ t('ADMIN_TRAILER_COMPATIBLE_VEHICLES_LABEL') }
                    />
                  }
                  </Field>
                  { errors.compatibleVehicles && touched.compatibleVehicles && (
                    <div className="has-error px-4">
                      <small className="help-block">{ errors.compatibleVehicles }</small>
                    </div>
                  )}
                </div>
              </div>
            </div>
            <div className="row">
              <div className="col-md-8 col-md-offset-2 text-center">
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