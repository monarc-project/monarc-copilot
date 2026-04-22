(function(global) {
  'use strict';

  var React = global.React;

  if (!React) {
    global.MonarcCopilot = global.MonarcCopilot || {};
    global.MonarcCopilot.runtimeMissing = true;
    return;
  }

  var createElement = React.createElement;
  var useEffect = React.useEffect;
  var useRef = React.useRef;
  var useState = React.useState;

  function serializeQuery(params) {
    var parts = [];
    Object.keys(params).forEach(function(key) {
      var value = params[key];
      parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value == null ? '' : value));
    });

    return parts.join('&');
  }

  function buildRequestUrl(anrId, prompt, pageContext) {
    return 'api/client-anr/' + anrId + '/copilot?' + serializeQuery({
      question: prompt,
      routeName: pageContext.routeName || '',
      tabIndex: pageContext.tabIndex || 0,
      tabLabel: pageContext.tabLabel || '',
      selectedObjectUuid: pageContext.selectedObjectUuid || '',
      selectedInstanceId: pageContext.selectedInstanceId || 0,
      selectedRiskId: pageContext.selectedRiskId || 0,
      selectedOpRiskId: pageContext.selectedOpRiskId || 0
    });
  }

  function getConfidenceLabel(confidence, labels) {
    if (confidence >= 90) {
      return labels.highConfidenceLabel || 'High';
    }
    if (confidence >= 75) {
      return labels.mediumConfidenceLabel || 'Medium';
    }

    return labels.lowConfidenceLabel || 'Low';
  }

  function getAuthToken() {
    try {
      if (!global.localStorage) {
        return null;
      }

      return global.localStorage.getItem('auth_token')
        || global.localStorage.getItem('ls.auth_token')
        || null;
    } catch (error) {
      return null;
    }
  }

  function requestCopilot(anrId, prompt, pageContext, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    var token = getAuthToken();

    xhr.open('GET', buildRequestUrl(anrId, prompt, pageContext), true);
    xhr.setRequestHeader('Accept', 'application/json');
    if (token) {
      xhr.setRequestHeader('token', token);
    }
    xhr.onreadystatechange = function() {
      var errorMessage;
      var payload;

      if (xhr.readyState !== 4) {
        return;
      }

      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          payload = JSON.parse(xhr.responseText);
        } catch (error) {
          onError('Invalid JSON response.');
          return;
        }

        onSuccess(payload);
        return;
      }

      try {
        payload = JSON.parse(xhr.responseText);
      } catch (error) {
        payload = null;
      }

      errorMessage = payload && payload.errors && payload.errors[0] && payload.errors[0].message
        ? payload.errors[0].message
        : '';

      onError(errorMessage);
    };
    xhr.onerror = function() {
      onError('');
    };
    xhr.send();

    return xhr;
  }

  function requestCopilotFromProps(props, prompt, pageContext, onSuccess, onError) {
    if (typeof props.requestCopilot === 'function') {
      return props.requestCopilot(prompt, pageContext, onSuccess, onError);
    }

    return requestCopilot(props.anrId, prompt, pageContext, onSuccess, onError);
  }

  function renderSuggestion(suggestion) {
    if (!suggestion) {
      return null;
    }

    return createElement(
      'section',
      { className: 'monarc-copilot__section' },
      createElement('div', { className: 'monarc-copilot__section-title' }, suggestion.title || 'Suggestion'),
      suggestion.text ? createElement('div', { className: 'monarc-copilot__body monarc-copilot__body--spaced' }, suggestion.text) : null,
      suggestion.items && suggestion.items.length ? createElement(
        'div',
        { className: 'monarc-copilot__list' },
        suggestion.items.map(function(item, index) {
          return createElement(
            'div',
            { className: 'monarc-copilot__list-item', key: item.label + '-' + index },
            createElement('div', { className: 'monarc-copilot__list-label' }, item.label || ''),
            item.detail ? createElement('div', { className: 'monarc-copilot__list-detail' }, item.detail) : null,
            item.why ? createElement('div', { className: 'monarc-copilot__list-why' }, item.why) : null
          );
        })
      ) : null,
      suggestion.why ? createElement('div', { className: 'monarc-copilot__caption' }, suggestion.why) : null
    );
  }

  function renderSources(labels, response) {
    if (!response || !response.sources || !response.sources.length) {
      return null;
    }

    return createElement(
      'section',
      { className: 'monarc-copilot__section' },
      createElement('div', { className: 'monarc-copilot__section-title' }, labels.sourcesTitle || 'Sources'),
      createElement(
        'div',
        { className: 'monarc-copilot__list' },
        response.sources.map(function(source, index) {
          return createElement(
            'div',
            { className: 'monarc-copilot__list-item monarc-copilot__list-item--source', key: (source.title || 'source') + '-' + index },
            createElement('div', { className: 'monarc-copilot__list-label' }, source.title || ''),
            createElement('div', { className: 'monarc-copilot__list-detail' }, source.detail || '')
          );
        })
      )
    );
  }

  function CopilotPanel(props) {
    var labels = props.labels || {};
    var presets = props.presets || [];
    var requestRef = useRef(null);
    var [question, setQuestion] = useState('');
    var [loading, setLoading] = useState(false);
    var [error, setError] = useState(null);
    var [response, setResponse] = useState(null);

    useEffect(function() {
      return function() {
        if (requestRef.current && typeof requestRef.current.abort === 'function') {
          requestRef.current.abort();
        }
      };
    }, []);

    function submitPrompt(prompt) {
      var pageContext;
      var normalizedPrompt = (prompt || '').trim();

      if (!normalizedPrompt || loading || !props.anrId) {
        return;
      }

      pageContext = props.getPageContext ? (props.getPageContext() || {}) : {};

      if (requestRef.current && typeof requestRef.current.abort === 'function') {
        requestRef.current.abort();
      }

      setQuestion(normalizedPrompt);
      setLoading(true);
      setError(null);

      requestRef.current = requestCopilotFromProps(
        props,
        normalizedPrompt,
        pageContext,
        function(payload) {
          requestRef.current = null;
          setResponse(payload);
          setLoading(false);
        },
        function(message) {
          requestRef.current = null;
          setError(message || labels.genericError || 'The copilot could not generate guidance for this screen.');
          setLoading(false);
        }
      );
    }

    function submitQuestion(event) {
      if (event && event.preventDefault) {
        event.preventDefault();
      }

      submitPrompt(question);
    }

    function runPreset(preset) {
      if (!preset || !preset.question || loading) {
        return;
      }

      setQuestion(preset.question);
      submitPrompt(preset.question);
    }

    return createElement(
      'section',
      { className: 'monarc-copilot' },
      createElement(
        'div',
        { className: 'monarc-copilot__hero' },
        createElement(
          'div',
          { className: 'monarc-copilot__hero-copy' },
          createElement('div', { className: 'monarc-copilot__eyebrow' }, labels.badge || 'React widget'),
          createElement('h3', { className: 'monarc-copilot__title' }, labels.title || 'Guidance copilot'),
          createElement(
            'p',
            { className: 'monarc-copilot__subtitle' },
            labels.subtitle || 'Read-only help for the current MONARC step, next actions, concepts, context text, and recommendations.'
          )
        ),
        createElement(
          'div',
          { className: 'monarc-copilot__preset-row' },
          presets.map(function(preset) {
            return createElement(
              'button',
              {
                className: 'monarc-copilot__preset',
                key: preset.key || preset.label,
                disabled: !!loading,
                type: 'button',
                onClick: function() { runPreset(preset); }
              },
              preset.label || preset.question || ''
            );
          })
        )
      ),
      createElement(
        'form',
        { className: 'monarc-copilot__composer', onSubmit: submitQuestion },
        createElement(
          'label',
          { className: 'monarc-copilot__label' },
          labels.inputLabel || 'Ask the copilot'
        ),
        createElement(
          'div',
          { className: 'monarc-copilot__composer-row' },
          createElement('input', {
            className: 'monarc-copilot__input',
            disabled: !!loading,
            onChange: function(event) { setQuestion(event.target.value); },
            placeholder: labels.placeholder || 'Example: What next on this page?',
            type: 'text',
            value: question
          }),
          createElement(
            'button',
            {
              className: 'monarc-copilot__ask',
              disabled: !!loading || !(question || '').trim() || !props.anrId,
              type: 'submit'
            },
            labels.askButton || 'Ask'
          )
        )
      ),
      loading ? createElement(
        'div',
        { className: 'monarc-copilot__status' },
        createElement('div', { className: 'monarc-copilot__spinner' }),
        createElement('span', null, labels.generating || 'Generating guidance...')
      ) : null,
      error ? createElement('div', { className: 'monarc-copilot__error' }, error) : null,
      response ? createElement(
        'article',
        { className: 'monarc-copilot__answer' },
        createElement(
          'div',
          { className: 'monarc-copilot__answer-head' },
          createElement('div', { className: 'monarc-copilot__section-title' }, labels.answerTitle || 'Copilot answer'),
          createElement(
            'div',
            { className: 'monarc-copilot__confidence' },
            (labels.confidenceTitle || 'Confidence') + ': ' + (response.confidence || 0) + '% (' +
              getConfidenceLabel(response.confidence || 0, labels) + ')'
          )
        ),
        createElement('div', { className: 'monarc-copilot__body' }, response.answer || ''),
        renderSuggestion(response.suggestion),
        renderSources(labels, response)
      ) : null
    );
  }

  global.MonarcCopilot = {
    CopilotPanel: CopilotPanel
  };
})(window);
